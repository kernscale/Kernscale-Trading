# Research bridge implementation receipt

16 November 2026. Local implementation only. Base application revision: `012f4248f70e255e32ad93f540aca5fb7e8c96fe`; bridge files remain uncommitted. Native R1 source checked at `816efb4643a7c33e2943b1a64f08971638f517e0`.

## Delivered

`bridge/{app,store,executor,worker,r1_tools}.py`, isolated `bridge/pyproject.toml`, bridge tests, README and uninstalled host admission templates. Authentication lane separately supplied `bridge/portfolio.py` and its eight tests; coordinator routes are wired here. No Laravel, Hosting, native ledger or scheduled-agent configuration changed by this lane.

API implements service bearer authentication, fixed workspace/tenant, server-derived researcher identities, cross-researcher session/run denial, immutable UUID/hash replay, payload conflict, session single-flight, durable monotonic event polling, explicit cancellation lifecycle and restart interruption without automatic provider prompt replay. A singleton coordinator lock protects startup reconciliation. Native source reports/portfolio/status use the separate read-only adapter.

Research executes through a Python worker dropped to an admitted dedicated researcher UID/GID with a scrubbed environment, 0700 state and no supplementary groups. Linux cgroup-v2 membership contains OMP descendants even though its SDK creates another process group. Restart/cancellation fences descendants; a surviving prior session group prevents new work. Actual Linux qualification is unavailable on this Mac, so real dispatch fails closed. The supervisor template is privileged and must be admitted explicitly on Agents2; it is not installed or production-approved.

OMP native loop receives R1 host tools with schema validation, computation/web/skill dispatch, source events and fsynced evidence replay. Built-in coding tools, extensions, arbitrary context/rule discovery and hidden reasoning output are disabled. Main/page-reader model route is Go/DeepSeek. Without optional backend Tauric data credentials, data tools are omitted and a capability event reports unavailability. No Tauric cloud account is required for the app; no Tauric synchronization/service app is started.

## Public dependency receipt

Read-only `git ls-remote https://github.com/can1357/oh-my-pi.git HEAD` returned `6f03614d761366972e80e84e25d1418ed678d2c7` (exit 0). Official SDK pyproject and nine Python package files were downloaded from that exact GitHub revision into `.orchestration/cache/omp-sdk`; no dependency source was copied into product code. SDK/CLI use this same pin. Source/runtime qualification remains false until the real CLI and host checks pass.

Offline dependency packages were installed only into `.orchestration/cache/bridge-venv` and `.orchestration/cache/bridge-python`; no global install. No provider requests or remote mutations occurred.

## Targeted validation

- Before tests existed, `python3 -m unittest discover -s bridge/tests -v`: exit 5, no tests. This was not treated as validation.
- `python3 -m unittest bridge.tests.test_bridge bridge.tests.test_portfolio -v`: exit 0, 18 tests green. Log `.orchestration/cache/bridge-api-tests.log`.
- `PYTHONPATH=.orchestration/cache/omp-sdk/src:/Users/jonasknppel/Businesses/Kernscale/repositories/products/Trading-R1:. .orchestration/cache/bridge-venv/bin/python -m unittest bridge.tests.test_native_adapter -v`: exit 0, two tests green. Log `.orchestration/cache/bridge-native-tests.log`. Uses actual pinned SDK transport and actual R1 worker/tool dispatch with a deterministic offline OMP subprocess fixture: validates registered-tool execution, native calculation, provenance/evidence persistence/chaining, expression rejection and disabled coding-tool discovery. No model/provider called.
- `python3 -m compileall -q bridge`: exit 0, syntax valid.
- `git diff --check -- bridge`: exit 0. Files are new/untracked, so this is supplementary; syntax/tests directly read the actual files.

## Explicit qualification gaps

No actual OMP CLI execution, live provider compatibility, Linux UID cross-read/cgroup fencing, real passkey/member flow, Ops-to-Agents2 private networking or deployment acceptance performed. CLI/SDK source pin is exact, but matching installed binary/source provenance must be checked during host admission. `runtime_qualified` defaults false. No production build/full suite/repository-wide lint or complete browser project ran. There is no bridge-specific format/lint runner in the repository; syntax and focused tests were run instead.

## Bounded review corrections

- Corrected the main and reader base URL to `https://opencode.ai/inference/go/openai/v1`. The [official current Go endpoints table](https://opencode.ai/docs/console/go/) explicitly lists `deepseek-v4.1-flash` at `/chat/completions` beneath this base; actual TradingAgents `tradingagents/llm_clients/openai_client.py` and `integrations/tradingcli_selection.py` agree. The earlier `/zen/go/v1` implementation value was incorrect. Both worker routes now use one admitted constant, with an offline assertion against generated OMP config.
- Reused R1 fetch/parser seams but replaced its fetcher because its existing request hook explicitly documents a DNS-rebinding window. New `bridge/network.py` resolves once per hop, denies every nonpublic answer (including IPv4-mapped IPv6 and mixed DNS), connects to the literal verified public IP, preserves original Host and TLS SNI, checks every redirect, disables ambient proxies and omits provider credentials. Native parser/body limits remain. The redirect fixture proves metadata redirection produces no second request.
- Privileged execution additionally refuses worker code or ancestor directories writable by untrusted users, requires root-protected OMP binary/ancestors and an operator-admitted SHA256 of the actual executable. Template qualification remains false. Worker waits for input before model/session admission; supervisor places it in the run cgroup before writing the request. Thus no provider prompt is admitted before cgroup membership. Actual host tests remain required.
- `python3 -m unittest bridge.tests.test_bridge bridge.tests.test_network bridge.tests.test_portfolio -v`: exit 0, **23 tests green**; latest `.orchestration/cache/bridge-api-tests.log`.
- `python3 -m unittest bridge.tests.test_http_contract -v`: exit 0, **one real loopback HTTP test green** against a temporary uvicorn subprocess: authenticated health, PUT, poll, persisted replay, cursor replay, 409 and cross-user 404. The initial sandbox attempt could not bind a socket (PermissionError); the authorized loopback-only check ran successfully with sandbox escalation. The test uses unavailable runtime admission, never a provider.
- Source and runtime remain an unqualified release candidate; nothing was deployed or cut over.

Final bounded-pass receipts: the same pinned SDK/R1 command now runs **three** native tests (including public-to-metadata redirect refusal), exit 0, `.orchestration/cache/bridge-native-tests.log`; real HTTP check rerun with explicitly absent identity admission, exit 0, `.orchestration/cache/bridge-http-tests.log`; 23 API/network/portfolio checks rerun, exit 0. Admission map credentials now require root ownership and mode 0600, not merely protection from modification. No actual Linux isolation qualification or deployment acceptance is claimed.

## Final manual-review integration pass

- Native pinned SDK documentation/source confirms `open_session(directory)` is valid; its distinct `switch_session(sessionPath)` API takes an actual file. Worker now persists `get_state().session_file` as a relative owned pointer, checks canonical containment/regular file/no symlinks, switches that exact file on the next turn and verifies the observed file matches. Actual SDK fixture executes two worker turns; a separate fixture rejects directories for `switch_session` and rejects escaped file pointers. Session selection is no longer marker-only/newest-file inference.
- Exact pinned CLI `args.ts` lines 398 and 404 implement `--no-extensions` and `--no-context-files`; downloaded official source under `.orchestration/cache/omp-pinned-args.ts`. RPC source also documents directory open semantics at lines 921 onwards. These are source receipts, not actual CLI startup acceptance.
- Removed unsupported `contextWindow:200000` and `maxTokens:16384` values. Official public Go model metadata request returned 403; model limits stay unknown until provider/runtime qualification. Native fixture asserts these invented fields are absent.
- Default-one `ThreadPoolExecutor` replaces per-request threads. Trusted `TRADING_BRIDGE_WORKERS` changes technical concurrency. Regression holds first session running and verifies a second distinct session remains durably queued until capacity frees.
- Event pages return `has_more` and `next_cursor`; a terminal 503-event regression drains 500 then three events with no loss or duplication.
- Typed immutable workspace snapshot joins the complete replay hash. Local `workspace_read`/`workspace_propose` tools emit flat validated proposals for human review, checking operation fields, card membership and snapshot versions. No application mutation occurs. Actual pinned SDK host-tool fixture records a thesis proposal while preserving the supplied snapshot.
- R1 terminal-specific structured `ask_user`/memory controls remain omitted through `RunPolicy.SCHEDULED`; this is a documented interactive-UX limitation, not a claim that scheduled TradingAgents policy changed. Plain model questions/next conversational turns remain possible.

Latest checks: **28 API/network/workspace/portfolio tests green**, exit 0 (`bridge-api-tests.log`); **five actual pinned SDK/R1 offline fixtures green**, exit 0 (`bridge-native-tests.log`); syntax and whitespace checks exit 0. Linux/actual CLI/provider qualification remains pending and `runtime_qualified` remains false. This pass freezes the bridge candidate for independent overall review; no production action occurred.

## Narrow metadata/schema receipt — 2026-11-16

Root's successful public [models.dev API](https://models.dev/api.json) read reported provider `opencode-go`, base `https://opencode.ai/inference/go/openai/v1`, model `deepseek-v4.1-flash`, context limit **1,000,000**, output limit **384,000**, reasoning true, input modalities text/image and output text. These are provider metadata, not application usage quotas. This supersedes the earlier statement that no metadata receipt was available; the separate direct Go endpoint request still returned 403.

Checked exact OMP revision `6f03614d761366972e80e84e25d1418ed678d2c7` directly: `packages/coding-agent/src/config/models-config-schema-bundle.ts` custom model schema declares `contextWindow?` and `maxTokens?` optional (lines 319 and 321; overrides also optional at 259/261). `models-config.ts` has optional fields at 24/26 and validates positivity only when provided at 195–199. Source cached as `.orchestration/cache/omp-model-schema.ts` and `omp-model-config.ts`.

The instruction was to add those values only if required by the schema. They are not required, so the frozen worker configuration and regression assertions remain unchanged. No runtime/package compatibility claim was added; actual CLI/provider/OS qualification remains a release gate. No code changed and no unchanged fixtures were rerun for this documentation-only receipt.

## Provider client/session headers — 2026-11-16

The [official Go integration requirements](https://opencode.ai/docs/console/go/#where-can-i-use-it) ask clients to identify themselves honestly and send a stable `x-opencode-session` per conversation (page lines 140–145). Existing approved native financial-research integration also records honest client/session headers in TradingAgents `docs/tradingcli-integration.md` lines 160–164. No billing/privacy/provider settings were changed.

Worker now supplies `User-Agent: kernscale-trading-hub/0.1 (omp-r1)` and the trusted immutable conversation UUID as `x-opencode-session` on both the OMP provider's supported `headers` configuration and R1 `ModelProfile.default_headers`. Exact pinned OMP `models-config-schema-bundle.ts` provider schema supports `headers` at line 401; R1 `providers/client.py` passes `profile.resolve_headers()` into its HTTP client. This identifies this application, not OpenCode itself.

Each OMP model configuration now lives inside its session's `.omp-agent` directory, passed directly as `RpcClient(env={PI_CODING_AGENT_DIR: ...})`, preventing concurrent sessions sharing a researcher from overwriting one global header/config file. No global environment session value is cached or changed.

Regression reproduced the missing-header failure (`reader.default_headers == {}`), then passed with actual pinned SDK/R1 offline fixture asserting equal main/reader headers, stable headers across two turns of one session and distinct headers for a second session. The five affected native fixtures passed; syntax and scoped whitespace checks passed. No live provider request occurred; actual provider/CLI/OS qualification remains pending. Bridge frozen again after this correction.

## Provider-free actual CLI qualification attempt — 2026-11-16

**Blocked before installation or CLI startup by the exact pinned runtime requirement.** Official package metadata downloaded from OMP revision `6f03614d761366972e80e84e25d1418ed678d2c7` identifies `@oh-my-pi/pi-coding-agent` version **18.21.0** with `engines.bun: >=1.4.4`; root `packageManager` independently declares `bun@>=1.4.4`. Installed `/Users/jonasknppel/.bun/bin/bun --version` returned **1.2.22**, exit 0. A scrubbed-environment version/requirement check returned exit **2**, `blocked_runtime_requirement`.

Source receipts and exact metadata/script inspection are under `.orchestration/cache/omp-cli-qualification/` (`root-package.json`, `coding-agent-package.json`, `upstream-README.md`, and machine-readable `receipt.json` with SHA256s). Inspected package `prepack` runs export-viewer generation and bundle generation; root `prepare` also generates the export viewer. These scripts were not executed. No global installation/upgrade, local dependency installation, upstream build, actual CLI startup, RPC get_state or session command occurred. The isolated version probe inherited no provider, Tauric or application credentials.

This is a concrete runtime prerequisite gap, not a failed provider call and not actual OMP CLI qualification. Prior actual Python SDK/R1 tests continue to use the offline OMP fixture and must not be described as real CLI acceptance. Next step requires an explicitly admitted local Bun >=1.4.4 or a verifiably same-revision standalone CLI artifact, then inspected pinned dependency installation and provider-free real RPC/session checks. Linux UID/cgroup and provider compatibility remain independent release gates. All bridge source remained frozen during this attempt.

## Project-local Bun and actual OMP CLI metadata/session check — 2026-11-16

The prior global-runtime blocker was bypassed **without upgrading or changing the global runtime**. Downloaded official [Bun 1.4.4 Apple Silicon release](https://github.com/oven-sh/bun/releases/tag/bun-v1.4.4) into `.orchestration/cache/omp-cli-qualification/tools/`. Inspected archive members before execution. ZIP SHA256 `c240d7f0ccb90c263a849fcdb09b7ee62630d921348ed6aafda86b9d577bc510` matches both the official GitHub release-asset digest and verified official SHASUMS manifest; local binary reports `1.4.4`, exit 0. Existing global Bun still reports `1.2.22`.

Official npm `@oh-my-pi/pi-coding-agent@18.21.0` tarball SHA512 matches registry integrity and the registry-published SLSA subject digest. The published SLSA metadata identifies GitHub repository `can1357/oh-my-pi` and exact `resolvedDependencies.gitCommit: 6f03614d761366972e80e84e25d1418ed678d2c7`. Metadata/attestations and inspected package are retained in the qualification cache. This is the registry-published provenance binding plus verified artifact digests, not an independent Sigstore trust-chain audit. Local `bun install --ignore-scripts` installed 63 packages in about 25 seconds, exit 0; no lifecycle scripts or upstream build ran. Actual CLI reports `omp/18.21.0`, exit 0.

Real exact-pin CLI and exact-pin Python SDK then passed metadata/session commands using project-local Bun, isolated HOME/cwd/PI config, an explicitly scrubbed process environment, `--offline`, and disabled tools/extensions/rules/skills/context-files/UI/title/LSP/PTY. An initial no-model attempt correctly exited requiring a selected model. The subsequent check selected only a synthetic `offline-fixture-model` provider at `http://127.0.0.1:9/v1` with a deliberately fake non-provider key. No prompt was submitted, no real credentials were inherited, and no actual provider/model request was made. Real RPC `ready`, `get_state`, `new_session` and `open_session(directory)` succeeded; process teardown confirmed true and stderr was empty. Qualification command exited 0.

**Verified integration finding requiring a narrow worker correction:** initial real `get_state().session_file` is an allocated JSONL filename whose file does **not** yet exist before any turn (`initial_file_exists:false`). The frozen worker currently resolves this file with `strict=True` before sending its first prompt; that would reject a real first turn. Source remained frozen during this qualification, and this finding was reported to the root agent immediately. Existing populated-file `switch_session` continuation was not exercised because producing a model turn was explicitly out of scope. `open_session` returned `resumed:false` for these intentionally empty sessions.

Receipts: `.orchestration/cache/omp-cli-qualification/{bun-official-release,npm-cli-metadata,npm-cli-provenance,actual-cli-receipt,prerequisite-receipt}.json`, `install.log`, and reproducible `qualify.py`. The earlier blocked receipt and initial no-model receipt remain as history. This establishes real local Mac CLI/SDK startup and metadata/session-command compatibility only. Actual Go request/tool-turn compatibility, populated conversation continuation, Linux UID/cgroup isolation and deployment remain unqualified release gates. No bridge source files, user/global PATH/config/home, native schedules or production services changed.

## Verified allocated-first-session correction — 2026-11-16

Narrow source change limited to `bridge/worker.py` session-path handling and its focused fixtures/tests. Every `owned_session_file` caller was inspected. Exact installed pinned `session/session-manager.ts` lazy gate (`#shouldHaveSessionFile`, around 1668; append path around 1905) writes a brand-new transcript only after an assistant message exists, matching the real CLI receipt's allocated-but-nonexistent initial filename.

Validator now permits a missing **last JSONL filename only** when explicitly called for initial pre-prompt allocation. Its parent must already exist inside the isolated session root, be UID-owned/private, and contain no symlink or escape; root/file symlinks, missing parents, foreign-owned files and untrusted-write permissions are refused. Continuation and post-prompt storage retain strict existing regular UID-owned JSONL checks. The worker no longer publishes a resume pointer before the prompt. It fsyncs and atomically replaces the pointer only once a real transcript exists, including a materialized failed turn; a purported successful turn without a transcript fails closed.

Focused evidence:

- Updated offline SDK fixture to return an allocated filename without touching it during `get_state`, then materialize it only after its deterministic assistant event. Before source edit, the native worker regression failed with the same `FileNotFoundError` as the actual CLI finding.
- `PYTHONPATH=.orchestration/cache/omp-sdk/src:/Users/jonasknppel/Businesses/Kernscale/repositories/products/Trading-R1:. .orchestration/cache/bridge-venv/bin/python -m unittest bridge.tests.test_native_adapter bridge.tests.test_session_paths -v`: exit 0, **eight tests green** (`.orchestration/cache/bridge-session-native-tests.log`). Includes first/continued worker turns, missing post-prompt transcript refusal, failed-turn pointer preservation, strict directory/escape rejection and owned-parent/file protections.
- Final additional symlink-root guard: `python3 -m unittest bridge.tests.test_session_paths -v`, exit 0, **two path tests green**.
- Direct assertion against the prior **actual CLI** receipt accepted its still-absent allocated filename only with the initial-allocation option and rejected the same path for strict continuation, exit 0. No CLI prompt or provider call was submitted.
- `python3 -m py_compile bridge/worker.py bridge/tests/fake_omp.py bridge/tests/test_native_adapter.py bridge/tests/test_session_paths.py`: exit 0. Scoped `git diff --check`: exit 0.

No unrelated bridge behavior changed. Actual provider/tool-turn compatibility, existing populated real-CLI continuation, Linux UID/cgroup isolation and deployment remain release gates.
