#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

die() { echo "backup: $*" >&2; exit 1; }
root=${BACKUP_ROOT:-/backups}
command=${1:-loop}
case $command in
  verify|drill|health) [[ -d $root ]] || die 'backup root is missing' ;;
  *) mkdir -p "$root/core" "$root/control" "$root/manifests" ;;
esac
cd "$root"

integer() { [[ $2 =~ ^[0-9]+$ ]] && (( 10#$2 >= $3 && 10#$2 <= $4 )) || die "invalid $1"; }
integer BACKUP_INTERVAL_SECONDS "${BACKUP_INTERVAL_SECONDS:-86400}" 60 31536000
integer BACKUP_RETENTION_DAYS "${BACKUP_RETENTION_DAYS:-30}" 1 3650
integer BACKUP_MIN_SUCCESSFUL_SETS "${BACKUP_MIN_SUCCESSFUL_SETS:-3}" 1 10000
integer BACKUP_HEALTH_TIMEOUT_SECONDS "${BACKUP_HEALTH_TIMEOUT_SECONDS:-172800}" 60 31536000
interval=${BACKUP_INTERVAL_SECONDS:-86400}
retention=${BACKUP_RETENTION_DAYS:-30}
minimum=${BACKUP_MIN_SUCCESSFUL_SETS:-3}
timeout=${BACKUP_HEALTH_TIMEOUT_SECONDS:-172800}
[[ ${BACKUP_ENABLED:-false} == true || ${BACKUP_ENABLED:-false} == false ]] || die 'invalid BACKUP_ENABLED'

now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
valid_id() { [[ $1 =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]]; }
status_field() { jq -r --arg k "$1" '.[$k] // empty' status.json 2>/dev/null || :; }
write_status() {
  local attempt success failure count id
  attempt=$(status_field lastAttemptAt); success=$(status_field lastSuccessAt)
  failure=$(status_field lastFailureAt); count=$(status_field consecutiveFailures)
  id=$(status_field lastBackupSetId); count=${count:-0}
  case $1 in
    attempt) attempt=$(now) ;;
    success) success=$(now); count=0; id=$2 ;;
    failure) failure=$(now); count=$((count+1)) ;;
  esac
  jq -n --arg a "$attempt" --arg s "$success" --arg f "$failure" --arg i "$id" --argjson c "$count" \
    '{lastAttemptAt:(if $a=="" then null else $a end),lastSuccessAt:(if $s=="" then null else $s end),lastFailureAt:(if $f=="" then null else $f end),consecutiveFailures:$c,lastBackupSetId:(if $i=="" then null else $i end)}' > status.json.partial
  mv -f status.json.partial status.json
}
targets() {
  case $1 in
    core) echo core ;;
    control) echo control ;;
    all) printf 'core\ncontrol\n' ;;
    *) die 'target must be core, control, or all' ;;
  esac
}
db_config() {
  case $1 in
    core) dbhost=${CORE_DB_HOST:-postgres}; dbname=${POSTGRES_DB:?}; dbuser=${POSTGRES_USER:?}; export PGPASSWORD=${POSTGRES_PASSWORD:?} ;;
    control) dbhost=${CONTROL_DB_HOST:-control-postgres}; dbname=${CONTROL_POSTGRES_DB:?}; dbuser=${CONTROL_POSTGRES_USER:?}; export PGPASSWORD=${CONTROL_POSTGRES_PASSWORD:?} ;;
  esac
}
manifest_ok() {
  local manifest=$1 id target path actual_size actual_hash
  [[ -f $manifest && ! -L $manifest ]] || return 1
  jq -e '(.schemaVersion == 1) and (.crossDatabaseAtomic == false) and
    (.backupSetId|type == "string") and (.createdAt|type == "string") and
    (.applicationVersion|type == "string") and
    (.releaseId == null or (.releaseId|type == "string")) and
    (.verification|type == "object") and
    (.databases|type == "object") and
    ((.databases|keys|length) >= 1) and
    ((.databases|keys) - ["core","control"] == [])' "$manifest" >/dev/null || return 1
  id=$(jq -r .backupSetId "$manifest"); valid_id "$id" || return 1
  [[ $(jq -r .createdAt "$manifest") =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || return 1
  [[ $manifest == "manifests/$id.json" ]] || return 1
  for target in $(jq -r '.databases|keys[]' "$manifest"); do
    jq -e --arg t "$target" --arg p "$target/$id.dump" \
      '.databases[$t].databaseLogicalName == $t and .databases[$t].file == $p and
       (.databases[$t].sizeBytes|type == "number" and . > 0) and
       (.databases[$t].sha256|type == "string" and test("^[a-f0-9]{64}$")) and
       .verification[($t + "PgRestoreList")] == true' "$manifest" >/dev/null || return 1
    path="$target/$id.dump"; [[ -f $path && ! -L $path ]] || return 1
    actual_size=$(stat -c %s "$path")
    [[ $actual_size == "$(jq -r --arg t "$target" '.databases[$t].sizeBytes' "$manifest")" ]] || return 1
    actual_hash=$(sha256sum "$path"); actual_hash=${actual_hash%% *}
    [[ $actual_hash == "$(jq -r --arg t "$target" '.databases[$t].sha256' "$manifest")" ]] || return 1
    pg_restore --list "$path" >/dev/null || return 1
  done
}
sorted_manifests() {
  local f stamp
  for f in manifests/*.json; do
    [[ -f $f && ! -L $f ]] || continue
    stamp=$(jq -r '.createdAt // empty' "$f" 2>/dev/null) || continue
    [[ $stamp =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || continue
    printf '%s %s\n' "$stamp" "$f"
  done | sort -r | cut -d' ' -f2-
}
latest_manifest() {
  local f
  while IFS= read -r f; do
    if manifest_ok "$f"; then echo "$f"; return 0; fi
  done < <(sorted_manifests)
  return 1
}
select_manifest() {
  local name=${1:-}
  if [[ -z $name ]]; then latest_manifest || die 'no verified backup set'; return; fi
  [[ $name =~ ^[0-9a-f-]+\.json$ ]] || die 'manifest must be a filename in backups/manifests'
  local id=${name%.json}; valid_id "$id" || die 'invalid manifest ID'
  echo "manifests/$name"
}
verify() {
  local manifest; manifest=$(select_manifest "${1:-}")
  manifest_ok "$manifest" || die "verification failed: $manifest"
  echo "verified $manifest"
}
backup_once() {
  local target=${1:-all} id stamp version release t tmp hash size databases='{}' checks='{}'
  targets "$target" >/dev/null
  exec 9>backup.lock
  flock -n 9 || die 'another backup or retention run is active'
  id=$(cat /proc/sys/kernel/random/uuid); valid_id "$id" || die 'cannot create backup ID'
  trap 'rc=$?; if ((rc != 0)); then cleanup_failed "$id"; write_status failure; fi' EXIT
  trap 'exit 1' INT TERM
  stamp=$(now)
  version=${APPLICATION_VERSION:-unversioned}; release=${PLATFORM_RELEASE_ID:-}
  [[ $version =~ ^[a-zA-Z0-9._+-]{1,128}$ ]] || die 'invalid APPLICATION_VERSION'
  [[ -z $release || $release =~ ^[a-zA-Z0-9._+-]{1,128}$ ]] || die 'invalid PLATFORM_RELEASE_ID'
  write_status attempt
  for t in $(targets "$target"); do
    db_config "$t"; tmp="$t/$id.dump.partial"
    pg_dump -h "$dbhost" -U "$dbuser" -d "$dbname" -Fc -f "$tmp"
    [[ -s $tmp ]] && pg_restore --list "$tmp" >/dev/null || die "$t dump failed"
    size=$(stat -c %s "$tmp"); hash=$(sha256sum "$tmp"); hash=${hash%% *}
    databases=$(jq -cn --argjson o "$databases" --arg t "$t" --arg f "$t/$id.dump" --arg h "$hash" --argjson s "$size" \
      '$o + {($t):{databaseLogicalName:$t,file:$f,sizeBytes:$s,sha256:$h}}')
    checks=$(jq -cn --argjson o "$checks" --arg t "$t" '$o + {($t+"PgRestoreList"):true}')
  done
  for t in $(targets "$target"); do mv "$t/$id.dump.partial" "$t/$id.dump"; done
  jq -n --arg id "$id" --arg at "$stamp" --arg v "$version" --arg r "$release" \
    --argjson db "$databases" --argjson checks "$checks" \
    '{schemaVersion:1,backupSetId:$id,createdAt:$at,applicationVersion:$v,
      releaseId:(if $r=="" then null else $r end),crossDatabaseAtomic:false,
      databases:$db,verification:$checks}' > "manifests/$id.json.partial"
  mv "manifests/$id.json.partial" "manifests/$id.json"
  manifest_ok "manifests/$id.json" || die 'published set failed verification'
  write_status success "$id"
  trap - EXIT INT TERM
  echo "backup set $id verified ($target)"
  apply_retention
}
cleanup_failed() {
  local id=$1
  valid_id "$id" || return 1
  rm -f -- "core/$id.dump.partial" "control/$id.dump.partial" \
    "core/$id.dump" "control/$id.dump" "manifests/$id.json.partial" "manifests/$id.json"
}
apply_retention() {
  local f id created epoch t count=0 cutoff
  local -a good=()
  declare -A protected=()
  cutoff=$(( $(date +%s) - retention*86400 ))
  while IFS= read -r f; do
    if manifest_ok "$f"; then good+=("$f"); fi
  done < <(sorted_manifests)
  for f in "${good[@]}"; do
    id=$(jq -r .backupSetId "$f")
    if (( count < minimum )); then protected[$id]=1; fi
    for t in core control; do
      if [[ -z ${protected[latest_$t]:-} ]] && jq -e --arg t "$t" '.databases|has($t)' "$f" >/dev/null; then
        protected[$id]=1; protected[latest_$t]=1
      fi
    done
    ((count+=1))
  done
  for f in "${good[@]}"; do
    id=$(jq -r .backupSetId "$f"); [[ -z ${protected[$id]:-} ]] || continue
    created=$(jq -r .createdAt "$f"); epoch=$(date -u -d "$created" +%s 2>/dev/null) || continue
    (( epoch < cutoff )) || continue
    # Recheck before deleting any exact, validated set paths.
    manifest_ok "$f" || continue
    for t in $(jq -r '.databases|keys[]' "$f"); do rm -f -- "$t/$id.dump"; done
    rm -f -- "$f"
  done
}
drill() {
  local manifest id t drill_db dbhost dbname dbuser
  manifest=$(select_manifest "${1:-}"); manifest_ok "$manifest" || die 'drill requires a verified manifest'
  id=$(jq -r .backupSetId "$manifest")
  for t in $(jq -r '.databases|keys[]' "$manifest"); do
   (
    db_config "$t"
    if [[ $t == core ]]; then drill_db="platform_restore_drill_${id//-/}"; else drill_db="platform_control_restore_drill_${id//-/}"; fi
    # Random UUID names avoid production database names; cleanup runs for every outcome.
    local rc=0
    createdb -h "$dbhost" -U "$dbuser" "$drill_db" || die "cannot create $t drill database"
    trap 'exit_code=$?; trap - EXIT; dropdb -h "$dbhost" -U "$dbuser" --if-exists --force "$drill_db" || exit_code=1; exit "$exit_code"' EXIT
    pg_restore -h "$dbhost" -U "$dbuser" -d "$drill_db" --no-owner --no-privileges --exit-on-error "$t/$id.dump" >/dev/null || rc=1
    if (( rc == 0 )); then
      if [[ $t == core ]]; then
        psql -X -A -t -h "$dbhost" -U "$dbuser" -d "$drill_db" -v ON_ERROR_STOP=1 -c \
          "SELECT (to_regclass('core.accounts') IS NOT NULL AND to_regclass('core.persons') IS NOT NULL AND to_regclass('core.organizations') IS NOT NULL AND to_regclass('core.privacy_destruction_evidence') IS NOT NULL AND to_regclass('platform_runtime.installations') IS NOT NULL AND to_regclass('platform_runtime.background_job_status') IS NOT NULL)::int" | grep -qx 1 || rc=1
        if (( rc == 0 )); then
          psql -X -A -t -h "$dbhost" -U "$dbuser" -d "$drill_db" -v ON_ERROR_STOP=1 -c \
            "SELECT EXISTS(SELECT 1 FROM public.platform_migrations WHERE migration_name = '031_platform_runtime_operational_status.sql')::int" | grep -qx 1 || rc=1
        fi
      else
        psql -X -A -t -h "$dbhost" -U "$dbuser" -d "$drill_db" -v ON_ERROR_STOP=1 -c \
          "SELECT (to_regclass('commercial.control_users') IS NOT NULL AND to_regclass('commercial.subscriptions') IS NOT NULL AND to_regclass('commercial.payments') IS NOT NULL AND to_regclass('commercial.deployments') IS NOT NULL AND to_regclass('commercial.deployment_entitlement_documents') IS NOT NULL AND to_regclass('commercial.background_job_status') IS NOT NULL)::int" | grep -qx 1 || rc=1
        if (( rc == 0 )); then
          psql -X -A -t -h "$dbhost" -U "$dbuser" -d "$drill_db" -v ON_ERROR_STOP=1 -c \
            "SELECT EXISTS(SELECT 1 FROM public.doctrine_migration_versions WHERE version = 'ControlPlane\\Migrations\\Version20260924002500')::int" | grep -qx 1 || rc=1
        fi
      fi
    fi
    (( rc == 0 )) || die "$t drill failed"
    echo "$t restore drill passed"
   )
  done
}
health() {
  if [[ ${BACKUP_ENABLED:-false} == true ]]; then
    local last epoch
    last=$(status_field lastSuccessAt); [[ -n $last ]] || return 1
    epoch=$(date -u -d "$last" +%s 2>/dev/null) || return 1
    (( $(date +%s) - epoch <= timeout )) || return 1
  fi
  deployment_health
}

source "$(dirname "${BASH_SOURCE[0]}")/deployment.sh"
deployment_config
shift || :
case $command in
  once) backup_once "${2:-${1:-all}}" ;;
  verify) [[ ${1:-} == --manifest ]] && shift; verify "${1:-}" ;;
  drill) [[ ${1:-} == --manifest ]] && shift; drill "${1:-}" ;;
  health) health ;;
  deployment) deployment_backup "${1:?deployment ID required}" ;;
  deployments) deployment_batch ;;
  verify-deployment) deployment_verify "${1:?deployment ID required}" "${2:-}" ;;
  drill-deployment) deployment_drill "${1:?deployment ID required}" "${2:-}" ;;
  deployment-status) deployment_status ;;
  status)
    platform_status=$(cat status.json 2>/dev/null || echo '{}')
    deployment_statuses=$(deployment_status | jq -s .)
    jq -n --argjson platform "$platform_status" --argjson deployments "$deployment_statuses" \
      '{platform:$platform,deployments:$deployments}' ;;
  retain-deployment) deployment_retention "${1:?deployment ID required}" ;;
  purge-decommissioned) deployment_purge ;;
  retain) exec 9>backup.lock; flock -n 9 || die 'another backup or retention run is active'; apply_retention ;;
  loop)
    next_platform=0
    while true; do
      tick=$(date +%s)
      if [[ ${BACKUP_ENABLED:-false} == true ]] && (( tick >= next_platform )); then
        bash /opt/backup/backup.sh once --target all || :
        next_platform=$(( $(date +%s) + interval ))
      fi
      if [[ ${DEPLOYMENT_BACKUP_ENABLED:-false} == true ]]; then
        bash /opt/backup/backup.sh deployments || :
      fi
      if [[ -n ${DECOMMISSIONED_DEPLOYMENT_BACKUP_RETENTION_DAYS:-} ]]; then
        bash /opt/backup/backup.sh purge-decommissioned || :
      fi
      sleep 30
    done ;;
  *) die 'usage: loop | once --target core|control|all | deployment UUID | deployments | verify | drill | verify-deployment UUID [manifest] | drill-deployment UUID [manifest] | status | health' ;;
esac
