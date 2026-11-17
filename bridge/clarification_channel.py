"""Private typed stdin controls; no worker HTTP credential or coordinator DB access."""
import asyncio
import json
import os
import select
import threading
from uuid import uuid4

from bridge.clarifications import MAX_CONTROL_BYTES, ClarificationRequest, bounded_json, canonical_answers


class ControlPump:
    def __init__(self, store, researcher, run_id, process, on_error):
        self.store, self.researcher, self.run_id, self.process = store, researcher, run_id, process
        self.on_error = on_error
        self.stop_event = threading.Event()
        os.set_blocking(process.stdin.fileno(), False)
        self.thread = threading.Thread(target=self.run, daemon=True, name='clarification-delivery')
        self.thread.start()

    def run(self):
        try:
            while not self.stop_event.is_set() and self.process.poll() is None:
                for control in self.store.clarification_controls(self.researcher, self.run_id):
                    data = (bounded_json(control) + '\n').encode('utf-8')
                    offset = 0
                    while offset < len(data) and not self.stop_event.is_set() and self.process.poll() is None:
                        try:
                            offset += os.write(self.process.stdin.fileno(), data[offset:])
                        except BlockingIOError:
                            self.stop_event.wait(0.05)
                self.stop_event.wait(0.2)  # Retry committed controls until an actual receipt is observed.
        except Exception:
            if not self.stop_event.is_set() and self.process.poll() is None:
                self.on_error()  # Fence the actual run rather than silently losing its control channel.

    def close(self):
        self.stop_event.set()
        self.thread.join(1)


class WorkerClarifications:
    def __init__(self, emit, stream=None):
        self.emit, self.pending = emit, {}
        self.lock = threading.Lock()
        self.closed = threading.Event()
        self.local = threading.local()
        self.reader = None
        if stream is not None:
            self.reader = threading.Thread(target=self.read, args=(stream,), daemon=True, name='clarification-controls')
            self.reader.start()

    def read(self, stream):
        try:
            try:
                descriptor = stream.fileno()
            except (AttributeError, OSError, ValueError):
                descriptor = None  # In-memory fixtures only.
            buffer = b''
            while not self.closed.is_set():
                if descriptor is None:
                    line = stream.readline(MAX_CONTROL_BYTES + 2)
                    if not line:
                        break
                    if not line.endswith('\n'):
                        raise ValueError('invalid control frame')
                    if len(line.encode('utf-8')) > MAX_CONTROL_BYTES + 1:
                        raise ValueError('control frame exceeds transport bound')
                    self.receive(json.loads(line))
                elif select.select([descriptor], [], [], 0.1)[0]:
                    chunk = os.read(descriptor, 4096)
                    if not chunk:
                        break
                    buffer += chunk
                    while b'\n' in buffer:
                        line, buffer = buffer.split(b'\n', 1)
                        if len(line) > MAX_CONTROL_BYTES:
                            raise ValueError('control frame exceeds transport bound')
                        self.receive(json.loads(line))
                    if len(buffer) > MAX_CONTROL_BYTES:
                        raise ValueError('control frame exceeds transport bound')
        except Exception:
            pass  # No raw input or exception text leaves this private channel.
        finally:
            self.closed.set()

    def receive(self, frame):
        bounded_json(frame)
        if not isinstance(frame, dict) or frame.get('type') not in ('clarification_answer', 'clarification_closed'):
            raise ValueError('invalid control frame')
        keys = {'type', 'request_id', 'answers', 'answer_digest'} if frame['type'] == 'clarification_answer' else {'type', 'request_id', 'status'}
        if set(frame) != keys or not isinstance(frame['request_id'], str):
            raise ValueError('invalid control fields')
        with self.lock:
            item = self.pending.get(frame['request_id'])
            if item is None or item['frame'] is not None:
                return  # Late/duplicate frames cannot reopen a call or create a second result.
            if frame['type'] == 'clarification_answer':
                _, digest = canonical_answers({'answers': frame['answers']}, item['questions'])
                if digest != frame['answer_digest']:
                    raise ValueError('invalid control digest')
            elif frame['status'] not in ('expired', 'cancelled', 'interrupted'):
                raise ValueError('invalid closure status')
            item['frame'] = frame

    async def ask(self, questions):
        context = self.local.context
        request = ClarificationRequest.model_validate({'request_id': str(uuid4()), 'tool_call_id': context.tool_call_id,
                                                       'questions': questions}).model_dump()
        item = {'questions': request['questions'], 'frame': None}
        with self.lock:
            self.pending[request['request_id']] = item
        try:
            self.emit('clarification_requested', request)
            while True:
                if context.cancelled:
                    return []  # The native SDK suppresses results for a cancelled host call.
                if self.closed.is_set():
                    raise asyncio.CancelledError()
                with self.lock:
                    frame = item['frame']
                if frame is not None:
                    if frame['type'] == 'clarification_closed':
                        if frame['status'] == 'expired':
                            return []  # Explicit no-answer; never choose a recommended option.
                        raise asyncio.CancelledError()
                    answers, digest = canonical_answers({'answers': frame['answers']}, request['questions'])
                    self.emit('clarification_consumed', {'request_id': request['request_id'], 'answer_digest': digest})
                    return [{'id': answer['id'], 'question': request['questions'][int(answer['id'])]['question'],
                             'answer': answer['answer']} for answer in answers]
                await asyncio.sleep(0.05)
        finally:
            with self.lock:
                self.pending.pop(request['request_id'], None)

    def close(self):
        self.closed.set()
        if self.reader is not None and self.reader is not threading.current_thread():
            self.reader.join(0.5)
