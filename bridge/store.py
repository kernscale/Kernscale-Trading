"""Durable scoped runs and replayable events; SQLite is never a financial ledger."""
import hashlib
import json
import os
import sqlite3
from datetime import datetime, timezone, timedelta
from contextlib import contextmanager
from pathlib import Path

TERMINAL = frozenset({'completed', 'failed', 'cancelled', 'interrupted'})


def now():
    return datetime.now(timezone.utc).isoformat()


class Conflict(Exception):
    pass


class Store:
    def __init__(self, path):
        self.path = str(path)
        directory = Path(path).parent
        directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        info = directory.stat()
        if info.st_uid != os.geteuid() or info.st_mode & 0o077:
            raise RuntimeError("bridge database directory must be private and owned by the coordinator")
        descriptor = os.open(self.path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            os.fchmod(descriptor, 0o600)
        finally:
            os.close(descriptor)
        with self.connect() as db:
            db.executescript('''
                CREATE TABLE IF NOT EXISTS sessions (
                    id TEXT PRIMARY KEY, researcher TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS runs (
                    id TEXT PRIMARY KEY, researcher TEXT NOT NULL, session_id TEXT NOT NULL,
                    digest TEXT NOT NULL, request TEXT NOT NULL, status TEXT NOT NULL,
                    created_at TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, run_id TEXT NOT NULL,
                    type TEXT NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL);
                CREATE INDEX IF NOT EXISTS event_run ON events(run_id, id);
                CREATE TABLE IF NOT EXISTS pre_admission_cancellations (
                    id TEXT PRIMARY KEY, researcher TEXT NOT NULL, created_at TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS clarifications (
                    request_id TEXT PRIMARY KEY, run_id TEXT NOT NULL, tool_call_id TEXT NOT NULL,
                    questions TEXT NOT NULL, status TEXT NOT NULL, expires_at TEXT NOT NULL,
                    answered_at TEXT, consumed_at TEXT, answers TEXT, answer_digest TEXT);
            ''')

    @contextmanager
    def connect(self):
        db = sqlite3.connect(self.path, timeout=30)
        db.row_factory = sqlite3.Row
        db.execute('PRAGMA journal_mode=WAL')
        try:
            with db:
                yield db
        finally:
            db.close()

    def create(self, researcher, run_id, body):
        encoded = json.dumps(body, sort_keys=True, separators=(',', ':'))
        digest = hashlib.sha256(encoded.encode()).hexdigest()
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            existing = db.execute('SELECT * FROM runs WHERE id=?', (run_id,)).fetchone()
            if existing:
                if existing['researcher'] != researcher:
                    raise KeyError(run_id)
                if existing['digest'] != digest:
                    raise Conflict('run ID already used with another request')
                return dict(existing), False
            cancelled = db.execute('SELECT researcher FROM pre_admission_cancellations WHERE id=?', (run_id,)).fetchone()
            if cancelled:
                if cancelled['researcher'] != researcher:
                    raise KeyError(run_id)
                raise Conflict('run ID cancelled before admission')
            session = db.execute('SELECT researcher FROM sessions WHERE id=?', (body['session_id'],)).fetchone()
            if session and session['researcher'] != researcher:
                raise KeyError(body['session_id'])
            active = db.execute("SELECT id FROM runs WHERE session_id=? AND status IN ('queued','running','waiting_for_input','cancel_requested')", (body['session_id'],)).fetchone()
            if active:
                raise Conflict('session already has an active run')
            db.execute('INSERT OR IGNORE INTO sessions VALUES (?,?)', (body['session_id'], researcher))
            db.execute('INSERT INTO runs VALUES (?,?,?,?,?,?,?)',
                       (run_id, researcher, body['session_id'], digest, encoded, 'queued', now()))
            self._event(db, run_id, 'status', {'status': 'queued'})
        return self.get(researcher, run_id), True

    def request_cancel(self, researcher, run_id):
        """Return committed status and whether a real run was admitted, under admission's lock."""
        if not isinstance(researcher, str) or not researcher.isascii() or not researcher.isdecimal() or int(researcher) < 1:
            raise ValueError('researcher must be positive')
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row = db.execute('SELECT researcher,status FROM runs WHERE id=?', (run_id,)).fetchone()
            if row:
                if row['researcher'] != researcher:
                    raise KeyError(run_id)
                status = row['status']
                if status not in TERMINAL and status != 'cancel_requested':
                    status = 'cancel_requested'
                    db.execute('UPDATE runs SET status=? WHERE id=?', (status, run_id))
                    self._event(db, run_id, 'status', {'status': status})
                self._close_clarifications(db, run_id, 'cancelled')
                return status, True
            receipt = db.execute('SELECT researcher FROM pre_admission_cancellations WHERE id=?', (run_id,)).fetchone()
            if receipt and receipt['researcher'] != researcher:
                raise KeyError(run_id)
            if not receipt:
                db.execute('INSERT INTO pre_admission_cancellations VALUES (?,?,?)', (run_id, researcher, now()))
            return 'cancelled', False

    def get(self, researcher, run_id):
        with self.connect() as db:
            row = db.execute('SELECT * FROM runs WHERE id=? AND researcher=?', (run_id, researcher)).fetchone()
        if not row:
            raise KeyError(run_id)
        return dict(row)

    @staticmethod
    def clarification_receipt(row):
        return {key: row[key] for key in ('request_id', 'status', 'expires_at', 'answered_at', 'consumed_at')}

    def _close_clarifications(self, db, run_id, status, *, expired_only=False):
        rows = db.execute("SELECT * FROM clarifications WHERE run_id=? AND status IN ('pending','accepted')", (run_id,)).fetchall()
        for row in rows:
            if expired_only and (row['status'] != 'pending' or row['expires_at'] > now()):
                continue
            db.execute('UPDATE clarifications SET status=? WHERE request_id=?', (status, row['request_id']))
            self._event(db, run_id, 'clarification_closed', {**self.clarification_receipt(row), 'status': status})
        if expired_only and db.execute("SELECT status FROM runs WHERE id=?", (run_id,)).fetchone()['status'] == 'waiting_for_input':
            if not db.execute("SELECT 1 FROM clarifications WHERE run_id=? AND status IN ('pending','accepted')", (run_id,)).fetchone():
                db.execute("UPDATE runs SET status='running' WHERE id=?", (run_id,))
                self._event(db, run_id, 'status', {'status': 'running'})

    def open_clarification(self, researcher, run_id, payload):
        from bridge.clarifications import ClarificationRequest
        request = ClarificationRequest.model_validate(payload).model_dump()
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            run = db.execute('SELECT * FROM runs WHERE id=? AND researcher=?', (run_id, researcher)).fetchone()
            if not run:
                raise KeyError(run_id)
            if run['status'] not in ('running', 'waiting_for_input'):
                return False
            existing = db.execute('SELECT * FROM clarifications WHERE request_id=?', (request['request_id'],)).fetchone()
            if existing:
                if existing['run_id'] != run_id or existing['tool_call_id'] != request['tool_call_id'] or json.loads(existing['questions']) != request['questions']:
                    raise Conflict('clarification identity already used')
                return False  # Never reopen a closed or answered request from a stale worker event.
            if db.execute("SELECT 1 FROM clarifications WHERE run_id=? AND status IN ('pending','accepted')", (run_id,)).fetchone():
                raise Conflict('a clarification is already active')
            expires = (datetime.now(timezone.utc) + timedelta(seconds=300)).isoformat()
            db.execute('INSERT INTO clarifications VALUES (?,?,?,?,?,?,?,?,?,?)',
                       (request['request_id'], run_id, request['tool_call_id'], json.dumps(request['questions']), 'pending', expires, None, None, None, None))
            self._event(db, run_id, 'clarification_requested', {**request, 'status': 'pending', 'expires_at': expires, 'answered_at': None, 'consumed_at': None})
            db.execute("UPDATE runs SET status='waiting_for_input' WHERE id=?", (run_id,))
            self._event(db, run_id, 'status', {'status': 'waiting_for_input'})
        return True

    def answer_clarification(self, researcher, run_id, request_id, body):
        from bridge.clarifications import canonical_answers
        # Expiry must commit even when the subsequent late answer is rejected.
        self.clarification_controls(researcher, run_id)
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            run = db.execute('SELECT * FROM runs WHERE id=? AND researcher=?', (run_id, researcher)).fetchone()
            if not run:
                raise KeyError(run_id)
            row = db.execute('SELECT * FROM clarifications WHERE request_id=? AND run_id=?', (request_id, run_id)).fetchone()
            if not row:
                raise KeyError(request_id)
            answers, digest = canonical_answers(body, json.loads(row['questions']))
            if row['answer_digest']:
                if row['answer_digest'] != digest:
                    raise Conflict('clarification already has a different answer')
            else:
                if row['status'] != 'pending' or run['status'] != 'waiting_for_input' or row['expires_at'] <= now():
                    raise Conflict('clarification no longer accepts an answer')
                db.execute("UPDATE clarifications SET status='accepted', answers=?,answer_digest=?,answered_at=? WHERE request_id=?",
                           (json.dumps(answers), digest, now(), request_id))
                row = db.execute('SELECT * FROM clarifications WHERE request_id=?', (request_id,)).fetchone()
                self._event(db, run_id, 'clarification_accepted', self.clarification_receipt(row))
            return {'id': run_id, 'status': run['status'], 'clarification': self.clarification_receipt(row)}

    def clarification_controls(self, researcher, run_id):
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            if not db.execute('SELECT 1 FROM runs WHERE id=? AND researcher=?', (run_id, researcher)).fetchone():
                raise KeyError(run_id)
            self._close_clarifications(db, run_id, 'expired', expired_only=True)
            rows = db.execute('SELECT * FROM clarifications WHERE run_id=? ORDER BY rowid DESC LIMIT 1', (run_id,)).fetchall()
            return [{'type': 'clarification_answer', 'request_id': row['request_id'], 'answers': json.loads(row['answers']), 'answer_digest': row['answer_digest']}
                    if row['status'] == 'accepted' else {'type': 'clarification_closed', 'request_id': row['request_id'], 'status': row['status']}
                    for row in rows if row['status'] in ('accepted', 'expired', 'cancelled', 'interrupted')]

    def consume_clarification(self, researcher, run_id, payload):
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            run = db.execute('SELECT status FROM runs WHERE id=? AND researcher=?', (run_id, researcher)).fetchone()
            if not run:
                raise KeyError(run_id)
            row = db.execute('SELECT * FROM clarifications WHERE request_id=? AND run_id=?', (payload.get('request_id'), run_id)).fetchone()
            if not row or row['status'] != 'accepted' or row['answer_digest'] != payload.get('answer_digest') or run['status'] != 'waiting_for_input':
                return False
            db.execute("UPDATE clarifications SET status='consumed',consumed_at=? WHERE request_id=?", (now(), row['request_id']))
            row = db.execute('SELECT * FROM clarifications WHERE request_id=?', (row['request_id'],)).fetchone()
            self._event(db, run_id, 'clarification_consumed', self.clarification_receipt(row))
            db.execute("UPDATE runs SET status='running' WHERE id=?", (run_id,))
            self._event(db, run_id, 'status', {'status': 'running'})
        return True

    def events(self, researcher, run_id, after=0):
        return self.event_page(researcher, run_id, after)["events"]

    def event_page(self, researcher, run_id, after=0):
        self.get(researcher, run_id)
        with self.connect() as db:
            rows = db.execute('SELECT id,type,payload,created_at FROM events WHERE run_id=? AND id>? ORDER BY id LIMIT 501', (run_id, after)).fetchall()
        events = [{**dict(row), 'payload': json.loads(row['payload'])} for row in rows[:500]]
        return {'events': events, 'has_more': len(rows) > 500, 'next_cursor': events[-1]['id'] if events else after}

    @staticmethod
    def _event(db, run_id, kind, payload):
        db.execute('INSERT INTO events(run_id,type,payload,created_at) VALUES (?,?,?,?)',
                   (run_id, kind, json.dumps(payload), now()))

    def event(self, run_id, kind, payload):
        with self.connect() as db:
            self._event(db, run_id, kind, payload)

    def transition(self, researcher, run_id, status):
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            row = db.execute('SELECT status FROM runs WHERE id=? AND researcher=?', (run_id, researcher)).fetchone()
            if not row:
                raise KeyError(run_id)
            if row['status'] in TERMINAL:
                return row['status']
            # A cancellation racing with admission must not be overwritten by running.
            if row['status'] == 'cancel_requested' and status == 'running':
                return row['status']
            db.execute('UPDATE runs SET status=? WHERE id=?', (status, run_id))
            self._event(db, run_id, 'status', {'status': status})
            if status in TERMINAL:
                self._close_clarifications(db, run_id, 'cancelled' if status == 'cancelled' else 'interrupted')
        return status

    def reconcile(self):
        """Only call while holding the coordinator's singleton lock."""
        with self.connect() as db:
            rows = db.execute("SELECT id FROM runs WHERE status IN ('queued','running','waiting_for_input','cancel_requested')").fetchall()
            for row in rows:
                db.execute("UPDATE runs SET status='interrupted' WHERE id=?", (row['id'],))
                self._event(db, row['id'], 'status', {'status': 'interrupted', 'reason': 'coordinator restarted; no automatic prompt replay'})
                self._close_clarifications(db, row['id'], 'interrupted')
