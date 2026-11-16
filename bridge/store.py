"""Durable scoped runs and replayable events; SQLite is never a financial ledger."""
import hashlib
import json
import os
import sqlite3
from datetime import datetime, timezone
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
            session = db.execute('SELECT researcher FROM sessions WHERE id=?', (body['session_id'],)).fetchone()
            if session and session['researcher'] != researcher:
                raise KeyError(body['session_id'])
            active = db.execute("SELECT id FROM runs WHERE session_id=? AND status IN ('queued','running','cancel_requested')", (body['session_id'],)).fetchone()
            if active:
                raise Conflict('session already has an active run')
            db.execute('INSERT OR IGNORE INTO sessions VALUES (?,?)', (body['session_id'], researcher))
            db.execute('INSERT INTO runs VALUES (?,?,?,?,?,?,?)',
                       (run_id, researcher, body['session_id'], digest, encoded, 'queued', now()))
            self._event(db, run_id, 'status', {'status': 'queued'})
        return self.get(researcher, run_id), True

    def get(self, researcher, run_id):
        with self.connect() as db:
            row = db.execute('SELECT * FROM runs WHERE id=? AND researcher=?', (run_id, researcher)).fetchone()
        if not row:
            raise KeyError(run_id)
        return dict(row)

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
        return status

    def reconcile(self):
        """Only call while holding the coordinator's singleton lock."""
        with self.connect() as db:
            rows = db.execute("SELECT id FROM runs WHERE status IN ('queued','running','cancel_requested')").fetchall()
            for row in rows:
                db.execute("UPDATE runs SET status='interrupted' WHERE id=?", (row['id'],))
                self._event(db, row['id'], 'status', {'status': 'interrupted', 'reason': 'coordinator restarted; no automatic prompt replay'})
