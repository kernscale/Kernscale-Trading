"""Stream one correlated native turn without retaining a turn-sized SDK event list."""
import threading


def stream_turn(client, message, emit):
    from omp_rpc import assistant_text, RpcTimeoutError

    changed = threading.Condition()
    state = {'result': None, 'answer': None, 'settled': False, 'id': None}

    def result(event):
        with changed:
            if state['id'] is None or event.id == state['id']:
                state['result'] = event
                changed.notify_all()

    def ended(event):
        if event.message.get('role') == 'assistant':
            state['answer'] = assistant_text(event.message)

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
            client.get_state()
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
        if client.protocol_errors or client.listener_errors:
            raise RuntimeError('Research event transport failed')
        answer = state['answer']
        if answer is None:
            raise RuntimeError('Research completed without a visible assistant message')
        return answer
    finally:
        for unsubscribe in subscriptions:
            unsubscribe()
