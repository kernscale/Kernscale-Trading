"""Private polling API. No browser connection owns a worker lifecycle."""
import fcntl
import hmac
import os
from concurrent.futures import ThreadPoolExecutor
from contextlib import asynccontextmanager
from uuid import UUID

from fastapi import Depends, FastAPI, Header, HTTPException, Query
from pydantic import BaseModel, ConfigDict, Field, model_validator

from bridge.attachment_tools import Attachment, validate_attachments
from bridge.store import Conflict, Store
from bridge.workspace_tools import Workspace

SCOPE = 'kernscale-trading'


class RunRequest(BaseModel):
    model_config = ConfigDict(extra='forbid')
    workspace_id: str
    tenant_id: str
    session_id: UUID
    prompt: str = Field(min_length=1)
    model: str = 'deepseek-v4.1-flash'
    workspace: Workspace = Field(default_factory=Workspace)
    attachments: list[Attachment] = Field(default_factory=list)

    @model_validator(mode='after')
    def admitted_attachments(self):
        validate_attachments(self.attachments)
        return self


def create_app(store=None, executor=None, token=None):
    from bridge.executor import IsolatedExecutor
    store = store or Store(os.environ.get('TRADING_BRIDGE_DB', '/var/lib/kernscale-trading-bridge/runs.sqlite'))
    executor = executor or IsolatedExecutor(store)
    token = token or os.environ.get('TRADING_BRIDGE_TOKEN', '')
    if len(token) < 32:
        raise RuntimeError('TRADING_BRIDGE_TOKEN must contain at least 32 characters')

    pool = ThreadPoolExecutor(max_workers=int(os.environ.get("TRADING_BRIDGE_WORKERS", "1")), thread_name_prefix="research")

    @asynccontextmanager
    async def lifespan(app):
        # Another coordinator must never reconcile a live coordinator's runs.
        with open(store.path + '.lock', 'a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            if hasattr(executor, "reconcile"):
                executor.reconcile()
            store.reconcile()
            try:
                yield
            finally:
                pool.shutdown(wait=True, cancel_futures=True)

    app = FastAPI(lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None)
    app.state.run_pool = pool

    def authenticate(authorization: str = Header(default=''), x_workspace_id: str = Header(default='')):
        if not hmac.compare_digest(authorization, 'Bearer ' + token):
            raise HTTPException(401, 'unauthorized')
        if x_workspace_id != SCOPE:
            raise HTTPException(403, 'workspace mismatch')

    def scoped(researcher, run_id):
        try:
            return store.get(researcher, str(run_id))
        except KeyError:
            raise HTTPException(404, 'run not found') from None

    @app.get('/health', dependencies=[Depends(authenticate)])
    def health():
        return {'status': 'ok', 'research': executor.capabilities(), 'cloud_sync': False}

    def native(call, *args):
        from bridge.portfolio import PortfolioUnavailable, ReportNotFound
        try:
            return call(*args)
        except ReportNotFound:
            raise HTTPException(404, 'report not found') from None
        except PortfolioUnavailable:
            raise HTTPException(503, 'native portfolio/report source unavailable') from None

    @app.get('/v1/portfolio', dependencies=[Depends(authenticate)])
    def portfolio():
        from bridge.portfolio import portfolio_snapshot
        return native(portfolio_snapshot)

    @app.get('/v1/reports', dependencies=[Depends(authenticate)])
    def reports():
        from bridge.portfolio import saved_reports
        return {'reports': native(saved_reports)}

    @app.get('/v1/reports/{report_id}', dependencies=[Depends(authenticate)])
    def report(report_id: UUID):
        from bridge.portfolio import report_artifact
        return native(report_artifact, str(report_id))

    @app.get('/v1/status', dependencies=[Depends(authenticate)])
    def status():
        from bridge.portfolio import service_status
        return native(service_status)

    @app.put('/v1/researchers/{researcher}/runs/{run_id}', dependencies=[Depends(authenticate)])
    def dispatch(researcher: int, run_id: UUID, body: RunRequest):
        if researcher < 1 or body.workspace_id != SCOPE or body.tenant_id != SCOPE:
            raise HTTPException(403, 'scope mismatch')
        if body.model != 'deepseek-v4.1-flash':
            raise HTTPException(422, 'model is not admitted')
        data = body.model_dump(mode='json')
        # Existing no-attachment run digests predate this optional field.
        if not data['attachments']:
            data.pop('attachments')
        try:
            row, created = store.create(str(researcher), str(run_id), data)
        except KeyError:
            raise HTTPException(404, 'session or run not found') from None
        except Conflict as exc:
            raise HTTPException(409, str(exc)) from None
        if created:
            pool.submit(executor.run, str(researcher), str(run_id), data)
        return {'id': row['id'], 'status': row['status']}

    @app.get('/v1/researchers/{researcher}/runs/{run_id}', dependencies=[Depends(authenticate)])
    def get(researcher: int, run_id: UUID):
        row = scoped(str(researcher), run_id)
        return {'id': row['id'], 'status': row['status'], 'session_id': row['session_id']}

    @app.get('/v1/researchers/{researcher}/runs/{run_id}/events', dependencies=[Depends(authenticate)])
    def events(researcher: int, run_id: UUID, after: int = Query(default=0, ge=0)):
        row = scoped(str(researcher), run_id)
        return {'id': row['id'], 'status': row['status'], **store.event_page(str(researcher), str(run_id), after)}

    @app.post('/v1/researchers/{researcher}/runs/{run_id}/cancel', dependencies=[Depends(authenticate)])
    def cancel(researcher: int, run_id: UUID):
        if researcher < 1:
            raise HTTPException(403, 'scope mismatch')
        try:
            status, admitted = store.request_cancel(str(researcher), str(run_id))
        except KeyError:
            raise HTTPException(404, 'run not found') from None
        if admitted and status == 'cancel_requested':
            executor.cancel(str(run_id))
        return {'id': str(run_id), 'status': status}

    return app
