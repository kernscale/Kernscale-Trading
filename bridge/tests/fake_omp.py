"""Offline protocol fixture: temporary session files, no model/network/market data."""
import json
import sys
import os
import time
import threading
from pathlib import Path


send_lock = threading.Lock()

def send(value):
    with send_lock:
        print(json.dumps(value), flush=True)


def finish_tool(command):
    if os.environ.get('OMP_FAKE_SESSION_DIR') and os.environ.get('OMP_FAKE_NO_PERSIST') != '1':
        session_file = active_file or Path(os.environ['OMP_FAKE_SESSION_DIR']) / 'native-session.jsonl'
        session_file.write_text('{"type":"message","message":{"role":"assistant"}}\n')
    send({'type': 'tool_execution_update', 'toolCallId': 'call-1', 'toolName': 'calc', 'partialResult': {'content': [{'type': 'text', 'text': 'offline progress'}]}})
    send({'type': 'tool_execution_end', 'toolCallId': 'call-1', 'toolName': 'calc', 'result': command['result'], 'isError': command.get('isError', False)})
    delay = float(os.environ.get('OMP_FAKE_DELAY', '0'))
    time.sleep(delay)
    if delay:
        send({'type': 'message_update', 'message': {'role': 'assistant', 'content': []}, 'assistantMessageEvent': {'type': 'text_delta', 'delta': 'x'}})
        time.sleep(delay)
    for index in range(int(os.environ.get('OMP_FAKE_BURST', '0'))):
        send({'type': 'message_update', 'message': {'role': 'assistant', 'content': []}, 'assistantMessageEvent': {'type': 'text_delta', 'delta': 'x'}})
    text = command['result']['content'][0]['text']
    message = {'role': 'assistant', 'content': [{'type': 'text', 'text': text}]}
    if os.environ.get('OMP_FAKE_USAGE'):
        message.update(usage=json.loads(os.environ['OMP_FAKE_USAGE']), model='offline-fixture-model',
                       provider='qualification-fixture', responseId='response-fixture',
                       providerPayload={'private': 'DO_NOT_EMIT_PRIVATE_MARKER'})
    send({'type': 'message_end', 'messageId': 'message-fixture', 'message': message})
    result = {'type': 'prompt_result', 'id': prompt_id, 'agentInvoked': True, 'status': 'completed', 'sessionSettled': True}
    if os.environ.get('OMP_FAKE_PROMPT_ERROR') == '1':
        result.update(status='error', error={'message': 'offline fixture failure', 'retryable': False})
    send(result)

send({'type': 'ready', 'protocolVersion': 1})
prompt_id = None
active_file = None
for line in sys.stdin:
    command = json.loads(line)
    kind = command['type']
    if kind == 'host_tool_result':
        if os.environ.get('OMP_FAKE_ASYNC_DELAY') == '1':
            threading.Thread(target=finish_tool, args=(command,), daemon=True).start()
        else:
            finish_tool(command)
        continue
    data = {}
    if kind == 'set_host_tools':
        data = {'toolNames': [tool['name'] for tool in command['tools']]}
    if kind == 'get_state':
        directory = Path(os.environ['OMP_FAKE_SESSION_DIR'])
        directory.mkdir(parents=True, exist_ok=True)
        session_file = active_file or directory / os.environ.get('OMP_FAKE_ALLOCATED_FILE', 'native-session.jsonl')
        active_file = session_file
        data = {'sessionId': 'offline-session', 'sessionFile': str(session_file), 'isSettled': False}
        if os.environ.get('OMP_FAKE_CONTEXT'):
            data['contextUsage'] = json.loads(os.environ['OMP_FAKE_CONTEXT'])
            data['systemPrompt'] = ['DO_NOT_EMIT_PRIVATE_MARKER']
            data['model'] = {'id': 'offline-fixture-model', 'name': 'Fixture', 'api': 'openai-completions',
                'provider': 'qualification-fixture', 'baseUrl': 'http://DO_NOT_EMIT_PRIVATE_MARKER',
                'reasoning': False, 'input': ['text'],
                'cost': {'input': 999, 'output': 999, 'cacheRead': 999, 'cacheWrite': 999},
                'contextWindow': None, 'maxTokens': None,
                'headers': {'Authorization': 'DO_NOT_EMIT_PRIVATE_MARKER'}}
    if kind == 'switch_session':
        candidate = Path(command['sessionPath'])
        if not candidate.is_file():
            send({'type': 'response', 'id': command['id'], 'command': kind, 'success': False, 'error': 'a session JSONL file is required'})
            continue
        active_file = candidate
        data = {'cancelled': False}
    if kind == 'prompt':
        prompt_id = command['id']
    send({'type': 'response', 'id': command['id'], 'command': kind, 'success': True, 'data': data})
    if kind == 'prompt':
        if os.environ.get('OMP_FAKE_EXIT_ON_PROMPT') == '1':
            sys.exit(0)
        if os.environ.get('OMP_FAKE_LATE_ERROR') == '1':
            send({'type': 'response', 'id': command['id'], 'command': 'prompt', 'success': False, 'error': 'offline scheduling failure'})
            continue
        proposal = os.environ.get('OMP_FAKE_WORKSPACE') == '1'
        send({'type': 'tool_execution_start', 'toolCallId': 'call-1', 'toolName': 'workspace_propose' if proposal else 'calc', 'args': {'expression': '1+2'}})
        send({'type': 'host_tool_call', 'id': 'host-1', 'toolName': 'workspace_propose' if proposal else 'calc', 'toolCallId': 'call-1', 'arguments': {'operation': 'update_thesis', 'content': 'Proposed thesis', 'expected_version': 1} if proposal else {'expression': '1+2'}})
