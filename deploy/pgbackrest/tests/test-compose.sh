#!/usr/bin/env bash
set -euo pipefail
suite=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
export POSTGRES_ENV_FILE="$work/postgres.env" REDIS_CONFIG_FILE="$work/redis.conf"
export POSTGRES_IMAGE="ghcr.io/kernscale/trading/postgres:17-pgbackrest@sha256:$(printf %064d 1)"
export REDIS_IMAGE="redis:7@sha256:$(printf %064d 2)"
export TRADING_PGBACKREST_SECRETS="$work/secrets"
touch "$POSTGRES_ENV_FILE" "$REDIS_CONFIG_FILE"
mkdir "$TRADING_PGBACKREST_SECRETS"
base=$suite/../state.compose.yaml
docker compose -f "$base" config --no-env-resolution --format json >"$work/base.json"
docker compose -f "$base" -f "$suite/compose.overlay.yaml" config --no-env-resolution --format json >"$work/native.json"
jq -e -s '
  .[0] as $base | .[1] as $native |
  $native.name == $base.name and
  $native.services.redis == $base.services.redis and
  $native.volumes == $base.volumes and
  $native.networks.data == $base.networks.data and
  $native.services.postgres.env_file == $base.services.postgres.env_file and
  $native.services.postgres.healthcheck == $base.services.postgres.healthcheck and
  ($native.services.postgres.ports // []) == [] and
  ($native.services.postgres.networks | keys == ["backup-egress","data"]) and
  ($native.services.postgres.volumes as $volumes |
    $base.services.postgres.volumes | all(. as $original | $volumes | index($original) != null))
' "$work/base.json" "$work/native.json" >/dev/null
printf '%s=%s\n' POSTGRES_ENV_FILE "$POSTGRES_ENV_FILE" REDIS_CONFIG_FILE "$REDIS_CONFIG_FILE" \
  POSTGRES_IMAGE "$POSTGRES_IMAGE" REDIS_IMAGE "$REDIS_IMAGE" \
  TRADING_PGBACKREST_SECRETS "$TRADING_PGBACKREST_SECRETS" >"$work/release.env"
DOCKER_HOST=tcp://foreign.invalid COMPOSE_PROJECT_NAME=foreign "$suite/run-compose.sh" "$work/release.env" config
if TMUX=fixture "$suite/run-compose.sh" "$work/release.env" deploy 2>"$work/refused"; then exit 1; fi
grep -Fq 'canonical Trading path' "$work/refused"
if TMUX=fixture "$suite/run-compose.sh" "$work/release.env" rollback 2>"$work/refused"; then exit 1; fi
grep -Fq 'canonical Trading path' "$work/refused"
grep -Fx 'repo1-sftp-host-key-hash-type=sha256' "$suite/pgbackrest.conf"
grep -Fx 'expire-auto=n' "$suite/pgbackrest.conf"
echo 'Trading native overlay preserves Redis, the existing external database volume, private network and paired backup inputs'
