"""Adapt R1 contracts directly; never run the R1 model loop or service globals."""
import asyncio
import os
import json
from pathlib import Path
import threading
from dataclasses import replace


def build_tools(reader, emit, host_tool, session_dir, *, session_identity=None, clarifications=None):
    from trading_r1.assembly import build_run_registry, build_tool_registry, builtin_skill_registry
    from trading_r1.runtime.dispatch import dispatch
    from trading_r1.providers.usage import captured, metered
    from bridge.omp_client import reported_counts, metric_label, metric_number
    from trading_r1.runtime.run_state import RunPolicy
    from trading_r1.runtime.skill_registry import SkillSessionState
    from trading_r1.runtime.tool_registry import ToolSessionState
    from trading_r1.tools.web import FetchCache

    from trading_r1.runtime.evidence import EvidenceRecord, EvidenceStore
    evidence_path = Path(session_dir) / "evidence.jsonl"
    records = [EvidenceRecord.from_dict(json.loads(line)) for line in evidence_path.read_text().splitlines()] if evidence_path.exists() else []
    state = ToolSessionState(evidence=EvidenceStore(records))

    def save(record):
        with evidence_path.open("a") as output:
            output.write(json.dumps(record.to_dict()) + "\n")
            output.flush()
            os.fsync(output.fileno())
        emit("record", record.to_dict())

    persistence_errors = []

    def durable_record(record):
        try:
            save(record)
        except Exception as exc:
            persistence_errors.append(exc)

    state.evidence.observe(durable_record)
    from bridge.network import public_fetch
    base = build_tool_registry(FetchCache(), reader, fetcher=public_fetch)
    # Data access is optional. Retain local computation and web/skill tools without it.
    if not os.environ.get('TAURIC_API_KEY'):
        from trading_r1.runtime.tool_registry import ToolRegistry
        admitted = ToolRegistry()
        for name in base.names():
            spec = base.get(name)
            if spec.result_kind != 'data':
                admitted.register(spec)
        base = admitted
    registry = build_run_registry(base, builtin_skill_registry(), state, SkillSessionState(),
                                  emit, policy=RunPolicy.SCHEDULED)
    if clarifications is not None:
        from trading_r1.tools.core import build_ask_user_spec
        registry.register(replace(build_ask_user_spec(clarifications), description=(
            'Ask the user 1–3 short clarifying questions and wait for their answers. '
            'The server deadline is 300 seconds; expiry returns no answer. '
            'Clarification is not execution authority.')))
    lock = threading.Lock()

    def bind(name):
        def execute(args, context):
            with lock:
                if clarifications is not None:
                    clarifications.local.context = context
                with metered() as meter, captured() as calls:
                    result = asyncio.run(dispatch(name, args, registry=registry, session_state=state,
                                                  call_id=context.tool_call_id, emit=emit))
                reader_calls = meter.get(reader.name)
                if reader_calls:
                    counts = reported_counts(reader_calls, ('prompt', 'completion')) if calls else None
                    emit('r1_reader_usage', {'source': 'r1_reader_reported',
                        'native_session_id': metric_label((session_identity or {}).get('native_session_id')),
                        'tool_call_id': context.tool_call_id, 'profile': metric_label(reader.name),
                        'model': metric_label(reader.model), 'calls': metric_number(reader_calls.get('calls')),
                        'available': counts is not None, 'counts': counts, 'cost': None})
                if persistence_errors:
                    raise RuntimeError('Evidence persistence failed; research stopped')
                if not result.ok:
                    raise RuntimeError(result.text)
                return {'content': [{'type': 'text', 'text': result.for_model()}],
                        'details': {'r1_result': True, 'text': result.for_model(),
                                    'provenance': result.meta, 'evidence_id': result.evidence_id}}
        return execute

    tools = []
    for name in registry.names():
        spec = registry.get(name)
        tools.append(host_tool(name=name, description=spec.description,
            parameters=spec.args.json_schema(), execute=bind(name), load_mode='essential'))
    emit('capabilities', {'data_available': bool(os.environ.get('TAURIC_API_KEY')),
                          'tool_names': registry.names(), 'cloud_sync': False})
    return tools
