"""Stream one correlated native turn without retaining a turn-sized SDK event list."""
import threading
import math


def metric_number(value):
    if type(value) not in (int, float) or value < 0:
        return None
    return value if type(value) is int or math.isfinite(value) else None


def metric_label(value):
    return value if isinstance(value, str) and 0 < len(value) <= 200 and all(ord(c) >= 32 and ord(c) != 127 for c in value) else None


def reported_counts(values, fields):
    if not isinstance(values, dict):
        return None
    counts = {key: value for key in fields if (value := metric_number(values.get(key))) is not None}
    return counts if any(value > 0 for value in counts.values()) else None


def stream_turn(client, message, emit, *, native_session_id=None, initial_state=None):
    from omp_rpc import assistant_text, RpcTimeoutError

    changed = threading.Condition()
    state = {'result': None, 'answer': None, 'settled': False, 'id': None}
    last_context = None
    native_id = metric_label(native_session_id)

    def context(snapshot, *, final=False):
        nonlocal last_context, native_id
        native_id = metric_label(getattr(snapshot, 'session_id', None)) or native_id
        usage = getattr(snapshot, 'context_usage', None)
        model = getattr(snapshot, 'model', None)
        tokens = metric_number(getattr(usage, 'tokens', None))
        capacity = metric_number(getattr(usage, 'context_window', None))
        capacity = capacity if capacity is not None and capacity > 0 else None
        payload = {'source': 'runtime_estimate', 'native_session_id': native_id,
                   'model': metric_label(getattr(model, 'id', None)),
                   'provider': metric_label(getattr(model, 'provider', None)),
                   'available': tokens is not None, 'tokens': tokens, 'context_window': capacity,
                   'percent': metric_number(getattr(usage, 'percent', None)) if capacity is not None and tokens is not None else None,
                   'capacity_source': 'runtime_model_metadata', 'capacity_verified': False}
        if final or payload != last_context:
            emit('context', {**payload, 'final': final})
            last_context = payload

    def result(event):
        with changed:
            if state['id'] is None or event.id == state['id']:
                state['result'] = event
                changed.notify_all()

    def ended(event):
        if event.message.get('role') == 'assistant':
            state['answer'] = assistant_text(event.message)
            counts = reported_counts(event.message.get('usage'), ('input', 'output', 'cacheRead', 'cacheWrite', 'totalTokens', 'reasoningTokens'))
            emit('usage', {'source': 'omp_normalized', 'native_session_id': native_id,
                           'message_id': metric_label(getattr(event, 'message_id', None)),
                           'response_id': metric_label(event.message.get('responseId')),
                           'model': metric_label(event.message.get('model')), 'provider': metric_label(event.message.get('provider')),
                           'available': counts is not None, 'counts': counts, 'cost': None})

    def settled(event):
        with changed:
            state['settled'] = True
            changed.notify_all()

    def update(event):
        delta = event.assistant_message_event
        if delta.get('type') == 'text_delta':
            emit('token', {'text': delta['delta']})

    def tool_start(event):
        emit('tool_call', {'name': event.tool_name, 'call_id': event.tool_call_id, 'args': event.args})

    def tool_update(event):
        emit('tool_progress', {'name': event.tool_name, 'call_id': event.tool_call_id, 'result': event.partial_result})

    def tool_end(event):
        payload = {'name': event.tool_name, 'call_id': event.tool_call_id,
                   'ok': event.is_error is not True, 'result': event.result}
        if isinstance(event.result, dict):
            content = event.result.get('content', [])
            if isinstance(content, list):
                payload['text'] = '\n'.join(item['text'] for item in content
                    if isinstance(item, dict) and item.get('type') == 'text' and isinstance(item.get('text'), str))
            details = event.result.get('details')
            if isinstance(details, dict) and details.get('r1_result') is True:
                for key in ('text', 'provenance', 'evidence_id'):
                    if key in details:
                        payload[key] = details[key]
        emit('tool_result', payload)

    subscriptions = [client.on_prompt_result(result), client.on_message_end(ended),
                     client.on_session_settled(settled), client.on_message_update(update),
                     client.on_tool_execution_start(tool_start), client.on_tool_execution_update(tool_update),
                     client.on_tool_execution_end(tool_end)]
    try:
        context(initial_state if initial_state is not None else client.get_state())
        request_id = client.prompt(message)
        with changed:
            state['id'] = request_id
        while True:
            with changed:
                outcome = state['result']
                if outcome is not None and outcome.id == request_id:
                    break
                changed.wait(1)
            # Bounded technical RPC checks detect a dead/stuck transport, not elapsed research time.
            context(client.get_state())
            try:
                client.wait_for_idle(timeout=0)
            except RpcTimeoutError:
                pass  # Still running; elapsed model/tool time is not a research quota.
            if client.protocol_errors or client.listener_errors:
                raise RuntimeError('Research event transport failed')
        if outcome.status != 'completed':
            raise RuntimeError('research prompt did not complete')
        if not outcome.session_settled:
            while not state['settled'] and not client.get_state().is_settled:
                with changed:
                    changed.wait(1)
        context(client.get_state(), final=True)
        if client.protocol_errors or client.listener_errors:
            raise RuntimeError('Research event transport failed')
        answer = state['answer']
        if answer is None:
            raise RuntimeError('Research completed without a visible assistant message')
        return answer
    finally:
        for unsubscribe in subscriptions:
            unsubscribe()
