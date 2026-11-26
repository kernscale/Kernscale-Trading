#!/usr/bin/env bash
set -euo pipefail
[[ $# -eq 1 ]] || exit 2
image=$1
source_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
docker=(env -i HOME="$HOME" PATH="$PATH" docker --host unix:///var/run/docker.sock)
name=trading-guard-${RANDOM}-$$
volume=$name-data
data=/var/lib/postgresql/data
cleanup() {
  "${docker[@]}" rm -fv "$name" >/dev/null 2>&1 || true
  "${docker[@]}" volume rm "$volume" >/dev/null 2>&1 || true
}
trap cleanup EXIT
"${docker[@]}" volume create "$volume" >/dev/null
instance=(--name "$name" --network none --security-opt no-new-privileges:true
  --mount "type=volume,src=$volume,dst=$data"
  --env POSTGRES_DB=trading --env POSTGRES_USER=kernscale_trading_admin
  --env POSTGRES_HOST_AUTH_METHOD=trust)
guard=(--mount "type=bind,src=$source_dir/existing-cluster.sh,dst=/guard.sh,readonly" --entrypoint /guard.sh)
read_identity() {
  for attempt in {1..30}; do
    if "${docker[@]}" exec --user postgres "$name" psql -X -qAt -w -v ON_ERROR_STOP=1 -U kernscale_trading_admin -d trading \
      -c 'SELECT system_identifier FROM pg_control_system()' 2>/dev/null; then
      return
    fi
    sleep 1
  done
  "${docker[@]}" logs --tail 25 "$name" >&2
  return 1
}
check_refusal() {
  local expected=$1 identity=$2 output status=0
  output=$("${docker[@]}" run --rm "${instance[@]}" "${guard[@]}" \
    --env "TRADING_EXPECTED_SYSTEM_ID=$identity" "$image" postgres 2>&1) || status=$?
  [[ "$status" == 1 && "$output" == "$expected" ]] || {
    echo "Unexpected guarded startup result: exit $status, $output" >&2
    return 1
  }
}
check_refusal 'Refusing PostgreSQL startup without the existing cluster.' 7712345678901234567
# Initialize only the disposable fixture via the unmodified image entrypoint.
"${docker[@]}" run -d "${instance[@]}" "$image" postgres >/dev/null
identity=$(read_identity)
[[ "$identity" =~ ^[0-9]{16,20}$ ]]
"${docker[@]}" stop "$name" >/dev/null
"${docker[@]}" rm "$name" >/dev/null
check_refusal 'Refusing PostgreSQL startup for a different cluster.' 7712345678901234567
"${docker[@]}" run -d "${instance[@]}" "${guard[@]}" --env "TRADING_EXPECTED_SYSTEM_ID=$identity" "$image" postgres >/dev/null
[[ "$(read_identity)" == "$identity" ]]
echo 'Native PG17 guard refused empty/wrong clusters and started the same existing cluster.'
