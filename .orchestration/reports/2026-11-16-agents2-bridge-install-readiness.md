# Agents2 research bridge install readiness

16 November 2026. Read-only host investigation for application candidate `9dbd6c3` / implementation `a4ae734`; no installation, service change, credential copy or provider call authorized in this investigation.

## Observed host state

- SSH alias `kernscale2-agents` connects as `jonas`, UID 1000, to the intended Agents2 host. Linux x86_64, cgroup v2 (`cgroup2fs`); system Python is 3.14.4.
- `sudo -n -l`: exit 1, `sudo: interactive authentication is required`. No trading admission wrapper was found in the bounded `/usr/local/sbin` inventory; `/opt/kernscale/bin` does not exist. Existing `/opt/kernscale` applications are root-owned and not writable by this account. This establishes no usable passwordless app-bootstrap interface; it does not claim every possible sudo rule is absent.
- `/etc/kernscale-trading`, `/var/lib/kernscale-trading-bridge`, `/var/lib/kernscale-trading-researchers`, and `/sys/fs/cgroup/kernscale-trading` are absent. Example dedicated UIDs 21001/21002 have no passwd entries.
- Bun/OMP/uv are absent from the normal noninteractive PATH. Alternate `~/.local/bin/uv` exists. Existing `~/.local/bin/omp` is a user-owned mode-0700 wrapper reporting **omp/18.18.2**, exit 0; its version probe emitted the existing audit wrapper's `kernscale_run_id`. It is neither the required OMP 18.21.0 artifact nor a root-protected admitted worker binary. Checked alternate Bun locations did not contain Bun; no claim was made about an exhaustive host-wide search.
- Existing Go credential source `/home/jonas/.config/tradingagents/go.env`: UID/GID 1000, mode 0600; key names **OPENCODE_GO_API_KEY**, **PAPER_GO_APPROVED**. Values were never printed, copied or included in receipts. Native `paper.env` exists separately, mode 0600. Bridge worker uses `OPENCODE_API_KEY`, requiring explicit protected server-side mapping during admission rather than changing native scheduled-agent credentials.
- Native R1 checkout `/home/jonas/repositories/products/Trading-R1` is clean at **748ff7fcd9b9ed9bea7797e2f529f4414eb25a35**. Required previously qualified revision **816efb4643a7c33e2943b1a64f08971638f517e0** is not present as a Git object there. Exact SHA256s for assembly.py, runtime/dispatch.py, contract/spec.py and providers/profiles.py match that qualified local revision, but this is only a seam comparison, not whole-revision/runtime qualification. Existing R1 `.venv` is user-owned mode 0775; it should not be treated as an immutable bridge dependency environment.

## Minimal viable admission step

An operator must perform one reviewed **app-scoped privileged bootstrap through ordinary sudo/root access** on Agents2. No existing passwordless trading-host interface was established. LXD membership and the installed Docker command are not an admitted replacement for root bootstrap, and neither was used as a privilege workaround; no PVE route was used.

That bootstrap must:

1. Install the exact root-protected bridge artifact and its own immutable Python/R1 environment under an app-owned `/opt/kernscale/...` path, leaving existing R1/TradingAgents/Trading-CLI checkouts and services intact. Install pinned OMP 18.21.0 / commit `6f03614d761366972e80e84e25d1418ed678d2c7` and a compatible Linux runtime (Bun >=1.4.4 if using the npm CLI), with artifact digests/provenance.
2. Admit each actual researcher to a unique unprivileged UID/GID >=10000 and UID-owned mode-0700 home/session state. Create mode-0600 root-owned researcher admission/credential files and a private coordinator DB directory. Provision only actual approved identities, not arbitrary example users.
3. Provide the literal app-owned cgroup-v2 subtree expected by the executor and a confined root supervisor service with sufficient UID/GID/cgroup rights. Qualify descendant fencing, cross-UID denial, existing-file ownership checks and restart behavior before setting any `runtime_qualified:true`.
4. Map the existing approved Go credential to the worker's expected key privately, without editing native scheduler configuration or exposing values. Supply an app-scoped service token to the app/bridge through protected files.
5. Admit private Ops-to-Agents2 ingress. The current unit binds **127.0.0.1:8863**, so it needs an explicitly admitted private proxy/route or a narrowly bound private listener/firewall rule; an Ops URL alone does not make that loopback listener reachable. Keep bearer authentication and application identity scoping intact.

If later automation needs privileged host management, expose only a reviewed literal app-scoped wrapper through existing host policy; do not grant broad passwordless sudo or create a container/VM-host escape workaround.

## Check receipts and limits

Three bounded `ssh -o BatchMode=yes -o ConnectTimeout=10 kernscale2-agents 'python3 -'` inventory calls exited 0. Embedded `sudo -n -l` exited 1; `getent passwd 21001 21002` exited 2; R1 qualified-object lookup exited 128; R1 clean-status/revision/core-fingerprint checks and existing OMP version probe exited 0. No secrets were included in output. The version probe may have been recorded by the existing audited wrapper; no service or scheduler command ran.

No remote files were edited, no privileges broadened, no package installed, no key values copied and no provider/model request made. Native ledger and scheduled-run behavior were preserved. Linux isolation, Go compatibility, existing populated-session continuation and actual installed release acceptance remain pending qualification.

## Separately authorized synthetic Go probe

After the read-only inventory, the root agent explicitly authorized one real provider qualification request under existing release authorization. The private mode-0600 credential file was opened without symlink following and parsed as data, never shell-sourced. `PAPER_GO_APPROVED` was checked privately before using the key. No credential value, request headers or raw model response was printed or copied into receipts.

One synthetic fixed acknowledgment request to the exact approved `https://opencode.ai/inference/go/openai/v1/chat/completions` route with `deepseek-v4.1-flash` passed: **HTTP 200**, returned model **deepseek-v4.1-flash**, expected acknowledgment **true**, command exit **0**. Provider usage supplied **28 prompt / 6 completion / 34 total tokens**. The probe-only 64-token bound did not change application usage policy. Honest application user agent and stable synthetic conversation header were sent; proxies/redirects were disabled, and no fallback or retry occurred. Only a trivial nonfinancial instruction was sent, with no private source/data.

Safe receipt: `.orchestration/cache/go-provider-probe/receipt.json`. This checks the API route/model/approved credential, **not** actual isolated OMP/R1 behavior, Linux UID/cgroup enforcement or deployment. No researcher worker was run under UID 1000; no package/service/schedule/ledger or host configuration was changed by this probe.
