"""Offline protocol fixture: no model, network, file access or market data."""
import json
import sys
import os
from pathlib import Path


def send(value):
    print(json.dumps(value), flush=True)


send({'type': 'ready', 'protocolVersion': 1})
prompt_id = None
for line in sys.stdin:
    command = json.loads(line)
    kind = command['type']
    if kind == 'host_tool_result':
        if os.environ.get('OMP_FAKE_SESSION_DIR') and os.environ.get('OMP_FAKE_NO_PERSIST') != '1':
            session_file = Path(os.environ['OMP_FAKE_SESSION_DIR']) / 'native-session.jsonl'
            session_file.write_text('{"type":"message","message":{"role":"assistant"}}\n')
        text = command['result']['content'][0]['text']
        send({'type': 'message_end', 'message': {'role': 'assistant', 'content': [{'type': 'text', 'text': text}]}})
        result = {'type': 'prompt_result', 'id': prompt_id, 'agentInvoked': True, 'status': 'completed', 'sessionSettled': True}
        if os.environ.get('OMP_FAKE_PROMPT_ERROR') == '1':
            result.update(status='error', error={'message': 'offline fixture failure', 'retryable': False})
        send(result)
        continue
    data = {}
    if kind == 'set_host_tools':
        data = {'toolNames': [tool['name'] for tool in command['tools']]}
    if kind == 'get_state':
        directory = Path(os.environ['OMP_FAKE_SESSION_DIR'])
        directory.mkdir(parents=True, exist_ok=True)
        session_file = directory / 'native-session.jsonl'
        data = {'sessionId': 'offline-session', 'sessionFile': str(session_file)}
    if kind == 'switch_session':
        candidate = Path(command['sessionPath'])
        if not candidate.is_file():
            send({'type': 'response', 'id': command['id'], 'command': kind, 'success': False, 'error': 'a session JSONL file is required'})
            continue
        data = {'cancelled': False}
    if kind == 'prompt':
        prompt_id = command['id']
    send({'type': 'response', 'id': command['id'], 'command': kind, 'success': True, 'data': data})
    if kind == 'prompt':
        proposal = os.environ.get('OMP_FAKE_WORKSPACE') == '1'
        send({'type': 'host_tool_call', 'id': 'host-1', 'toolName': 'workspace_propose' if proposal else 'calc', 'toolCallId': 'call-1', 'arguments': {'operation': 'update_thesis', 'content': 'Proposed thesis', 'expected_version': 1} if proposal else {'expression': '1+2'}})
