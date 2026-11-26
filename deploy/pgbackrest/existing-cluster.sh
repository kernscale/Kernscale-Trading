#!/usr/bin/env bash
set +x
set -euo pipefail
export LC_ALL=C

expected_dir=/var/lib/postgresql/data
[[ "$PGDATA" == "$expected_dir" && "$POSTGRES_DB" == trading &&
   "$POSTGRES_USER" == kernscale_trading_admin && ${TRADING_EXPECTED_SYSTEM_ID:-} =~ ^[0-9]{16,20}$ &&
   $# -ge 1 && "$1" == postgres ]] || {
  echo 'Trading PostgreSQL startup identity is invalid.' >&2; exit 1;
}
[[ -f "$PGDATA/PG_VERSION" && ! -L "$PGDATA/PG_VERSION" && $(<"$PGDATA/PG_VERSION") == 17 &&
   -s "$PGDATA/global/pg_control" && ! -L "$PGDATA/global/pg_control" ]] || {
  echo 'Refusing PostgreSQL startup without the existing cluster.' >&2; exit 1;
}
identifier=$(pg_controldata "$PGDATA" | awk -F ': *' '$1 == "Database system identifier" {print $2}')
[[ "$identifier" == "$TRADING_EXPECTED_SYSTEM_ID" ]] || {
  echo 'Refusing PostgreSQL startup for a different cluster.' >&2; exit 1;
}

shift
# Start the existing cluster directly. The image entrypoint can initialize a database.
exec gosu postgres postgres -D "$PGDATA" "$@" -c "data_directory=$PGDATA"
