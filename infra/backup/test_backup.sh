#!/usr/bin/env bash
set -Eeuo pipefail
set_id=${1:?source backup set ID required}
[[ $set_id =~ ^[0-9a-f-]{36}$ ]] || exit 1
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
mkdir -p "$test_root/core" "$test_root/control" "$test_root/manifests"
export BACKUP_ROOT=$test_root BACKUP_RETENTION_DAYS=1 BACKUP_MIN_SUCCESSFUL_SETS=2
engine=/opt/backup/backup.sh
for n in 1 2 3 4; do
  id="00000000-0000-4000-8000-00000000000$n"
  for target in core control; do
    cp "/source/$target/$set_id.dump" "$test_root/$target/$id.dump"
  done
  jq --arg id "$id" --arg at "2020-01-0${n}T00:00:00Z" \
    '.backupSetId=$id | .createdAt=$at |
     .databases.core.file=("core/"+$id+".dump") |
     .databases.control.file=("control/"+$id+".dump")' \
    "/source/manifests/$set_id.json" > "$test_root/manifests/$id.json"
  bash "$engine" verify --manifest "$id.json" >/dev/null
done
bash "$engine" retain
for n in 1 2; do
  id="00000000-0000-4000-8000-00000000000$n"
  [[ ! -e "$test_root/manifests/$id.json" && ! -e "$test_root/core/$id.dump" ]] || exit 1
done
for n in 3 4; do
  id="00000000-0000-4000-8000-00000000000$n"
  [[ -f "$test_root/manifests/$id.json" && -f "$test_root/core/$id.dump" ]] || exit 1
done
printf x >> "$test_root/core/00000000-0000-4000-8000-000000000003.dump"
if bash "$engine" verify --manifest 00000000-0000-4000-8000-000000000003.json >/dev/null 2>&1; then exit 1; fi
bash "$engine" verify >/dev/null
rm -f "$test_root/control/00000000-0000-4000-8000-000000000004.dump"
if bash "$engine" verify --manifest 00000000-0000-4000-8000-000000000004.json >/dev/null 2>&1; then exit 1; fi
echo '{}' > "$test_root/manifests/00000000-0000-4000-8000-000000000005.json.partial"
if bash "$engine" verify >/dev/null 2>&1; then exit 1; fi
jq -e 'keys == ["consecutiveFailures","lastAttemptAt","lastBackupSetId","lastFailureAt","lastSuccessAt"]' /source/status.json >/dev/null
echo 'backup manifest, corruption, partial, retention and status tests passed'
