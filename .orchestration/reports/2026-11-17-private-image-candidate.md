# Trading Hub private image candidate

Status: private source and image published; actual image configuration verified. **No Ops/Agents2 installation, production runtime acceptance or domain cutover.** Both acknowledgments are recorded; the owner deferred administrator access and deployment. The long-horizon goal remains incomplete.

Checked source: `86cd966c678d5aaf74b752beb7731691bf2bac0b`, private repository `kernscale/Kernscale-Trading`, `main`. Capitalio application frontend remains the accepted `27859f6c4ca5bfb3231820410cf83e65a2296c4b` implementation; later source changes validate concrete production mail transports and actual private image identity. This receipt is subsequent documentation.

## Observed publication

- [Successful exact candidate run44066150261](https://github.com/kernscale/Kernscale-Trading/actions/runs/44066150261): validation and publication jobs completed successfully. Target platform is Linux/amd64.
- Immutable image: `ghcr.io/kernscale/kernscale-trading@sha256:7b4a263b4e7df9c726676bb002e0ae2ef089bf289cbf7b039720d9629f37d69f`.
- Build timestamp: `2026-11-17T10:35:59Z`. The workflow pulled that exact digest and inspected actual repository digests, architecture/OS, runtime user and OCI labels. `deploy/verify_image.py` accepted exact commit/source/main/timestamp/version labels, Linux/amd64 and `kernscale` runtime user. Private package/name are mandatory; any present foreign REST repository association fails.
- The REST response omits the repository association. The authenticated package settings page independently showed source `kernscale/Kernscale-Trading`, the exact OCI source URL, inherited private repository access enabled, only the owning repository listed for Actions, and current visibility private. No settings were changed. The personal CLI lacks `read:packages`; its denied lookup is not treated as evidence against visibility.
- Artifact `trading-image-release` was downloaded to `.orchestration/cache/releases/44066150261/`. All six original JSON checksums match after resolving their CI `release/` prefix. The actual downloaded package/inspection inputs also passed a local `deploy/verify_image.py` invocation. Both release and verification JSON retain `runtimeAccepted:false`.

The earlier run43955234317 pushed images but failed its required nullable REST-field assertion twice. Those attempts are not accepted release receipts. The corrected workflow proves actual immutable image identity instead of assuming that optional API field is populated. No failed candidate was installed or promoted.

## Exact checks

All final checks below exited0; private verbose logs remain in `.orchestration/cache/`.

| Check | Observed result |
| --- | --- |
| Corrected Capitalio `npm run build` | Passed,3.39s; `capitalio-production-build.log` |
| CI explicit affected PHP feature selection |105 tests /397 assertions passed; `release-ci-final.log` |
| CI affected frontend type check | Passed |
| CI runtime source contract | Three tests passed |
| New image verifier fixtures, CI and root local | Five tests passed: nullable/matching repository, private/name/foreign repository, digest/platform/root, all missing/mismatched source labels, malformed expected identity |
| CI exact Capitalio source guard | Passed; all twelve original files retained |
| Changed workflow YAML, shell/Python syntax and `git diff --check` | Passed |
| Immutable release artifact checksums | Six original JSON files matched |
| Actual downloaded immutable image verification | Passed; exact digest/private package/actual config; runtime remains unaccepted |

The release metadata contains expected labels, while `image-inspection.json` and `image-verification.json` provide the actual image readback. Buildx provenance and requested SBOM attestations are retained by the build; independent registry-attestation subject verification is not claimed. Main is not branch-protected; protected-main enforcement is not claimed.

## Local review and remaining gates

Existing Laravel and Vite servers were reused on loopback ports8007/5177 and both returned200. Chrome opened `http://localhost:8007/dashboard` and correctly reached the protected login screen. The app tab was kept open. The owner subsequently requested the first real local owner account; it was created in the checked local SQLite database with an unretained random initial password and Laravel's hashed, expiring password-setup token. Exactly one approved local owner exists. The actual account-specific setup page returned200 and was queued in the Codex browser panel. Chrome's URL policy blocked a local-file launcher; no workaround was attempted. Human password/passkey/MFA completion remains pending. No credentials appear in this receipt and no authentication bypass was added.

The exact Capitalio browser screenshots, responsive interactions and source parity are recorded in [the design acceptance receipt](2026-11-16-capitalio-acceptance.md). Sample charts remain local-owner preview data. Native financial views require real verified snapshots; absent history, benchmark, risk methodology and income remain unavailable rather than becoming invented figures.

Still required: authenticated app-scoped Ops/Agents2 admission, actual container boot/migrations and worker fencing, qualified Linux OMP/R1 isolation and real session/tool continuation, protected mail delivery, canonical-origin authenticator acceptance, live native reconciliation, measured encrypted off-host restore and final route promotion/rollback. The existing paper ledger, risk settings, agent schedules and host services/routes were not changed.

Skipped: broad suites/repository-wide lint, local Docker startup, production runtime/physical devices, live legacy import, actual recovery and host/domain changes. Private publication and HTTP200 do not establish the full-system goal.
