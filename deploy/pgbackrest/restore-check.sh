#!/usr/bin/env bash
set +x
set -euo pipefail
umask 077
[[ $# == 6 ]] || {
  echo "Usage: $0 IMAGE PRIVATE_FILES READ_ONLY_USER READ_ONLY_REPO_PATH BACKUP_LABEL TARGET" >&2; exit 2;
}
[[ -n "${TMUX:-}" && $(hostname -s) == kernscale2-ops ]] || {
  echo 'Use the recovery tmux session on kernscale2-ops.' >&2; exit 2;
}
image=$1 files=$2 reader=$3 repo=$4 label=$5 target=$6
[[ "$image" =~ ^ghcr\.io/kernscale/trading/postgres:17-pgbackrest@sha256:[a-f0-9]{64}$ &&
   "$reader" =~ ^u[0-9]+-sub[0-9]+$ && "$reader" != u630285-sub4 &&
   ( "$repo" == /home || "$repo" == /home/pgbackrest/trading-hub ) &&
   "$label" =~ ^[0-9]{8}-[0-9]{6}F(_[0-9]{8}-[0-9]{6}[DI])?$ && "$target" =~ ^trading-[a-z0-9-]{6,55}$ ]]
[[ "$files" == /srv/kernscale/secrets/* && "$files" == "$(realpath -e "$files")" && -d "$files" ]]
for leaf in storagebox.key storagebox.known_hosts 10-cipher.conf; do
  [[ -f "$files/$leaf" && ! -L "$files/$leaf" ]]
done
directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
scratch_root=/srv/kernscale/backups/restore-tests
[[ -d "$scratch_root" && ! -L "$scratch_root" ]]
scratch=$(mktemp -d "$scratch_root/trading-native.XXXXXXXX")
network="kernscale-trading-restore-${scratch##*.}"
network=${network,,}
created=false
docker=(env -i HOME="$HOME" PATH="$PATH" docker --host unix:///var/run/docker.sock)
cleanup() {
  status=$?
  trap - EXIT
  cleanup_status=0
  if $created; then "${docker[@]}" network rm "$network" >/dev/null || cleanup_status=$?; fi
  if (( status == 0 && cleanup_status == 0 )); then
    rm -rf -- "$scratch" || cleanup_status=$?
  else
    printf 'Preserved isolated recovery scratch: %s\n' "$scratch" >&2
  fi
  printf 'Trading PITR exit=%s cleanup_exit=%s\n' "$status" "$cleanup_status" >&2
  (( status == 0 )) || exit "$status"
  exit "$cleanup_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
chown 999:999 "$scratch"
"${docker[@]}" network create "$network" >/dev/null
created=true
"${docker[@]}" run --rm --network "$network" --user 999:999 --read-only --cap-drop ALL \
  --security-opt no-new-privileges:true --memory=2g --pids-limit=128 \
  --tmpfs /tmp:mode=1777,size=64m \
  --tmpfs /var/run/postgresql:uid=999,gid=999,mode=0700,size=16m \
  --tmpfs /run/pgbackrest:uid=999,gid=999,mode=0700,size=16m \
  --mount "type=bind,src=$scratch,dst=/var/lib/postgresql/data" \
  --mount "type=bind,src=$files,dst=/run/trading-pgbackrest,readonly" \
  --mount "type=bind,src=$directory/pgbackrest.conf,dst=/etc/pgbackrest/pgbackrest.conf,readonly" \
  --mount "type=bind,src=$directory/verify-restored.sh,dst=/opt/verify-restored.sh,readonly" \
  --env "PGBACKREST_REPO1_SFTP_HOST=$reader.your-storagebox.de" \
  --env "PGBACKREST_REPO1_SFTP_HOST_USER=$reader" --env "PGBACKREST_REPO1_PATH=$repo" \
  --entrypoint bash "$image" /opt/verify-restored.sh "$label" "$target"
