#!/usr/bin/env bash
# Sourced by backup.sh. All paths are relative to BACKUP_ROOT.
deployment_config() {
  integer DEPLOYMENT_BACKUP_INTERVAL_SECONDS "${DEPLOYMENT_BACKUP_INTERVAL_SECONDS:-86400}" 60 31536000
  integer DEPLOYMENT_BACKUP_BATCH_SIZE "${DEPLOYMENT_BACKUP_BATCH_SIZE:-2}" 1 1000
  integer DEPLOYMENT_BACKUP_RETENTION_DAYS "${DEPLOYMENT_BACKUP_RETENTION_DAYS:-30}" 1 3650
  integer DEPLOYMENT_BACKUP_MIN_SUCCESSFUL_SETS "${DEPLOYMENT_BACKUP_MIN_SUCCESSFUL_SETS:-3}" 1 10000
  integer DEPLOYMENT_BACKUP_HEALTH_TIMEOUT_SECONDS "${DEPLOYMENT_BACKUP_HEALTH_TIMEOUT_SECONDS:-172800}" 60 31536000
  [[ ${DEPLOYMENT_BACKUP_ENABLED:-false} == true || ${DEPLOYMENT_BACKUP_ENABLED:-false} == false ]] || die 'invalid DEPLOYMENT_BACKUP_ENABLED'
  if [[ -n ${DECOMMISSIONED_DEPLOYMENT_BACKUP_RETENTION_DAYS:-} ]]; then
    integer DECOMMISSIONED_DEPLOYMENT_BACKUP_RETENTION_DAYS "$DECOMMISSIONED_DEPLOYMENT_BACKUP_RETENTION_DAYS" 1 3650
  fi
}
deployment_dir() { valid_id "$1" || die 'invalid deployment ID'; echo "deployments/$1"; }
deployment_inventory() {
  db_config control
  psql -X -A -t -F '|' -h "$dbhost" -U "$dbuser" -d "$dbname" -v ON_ERROR_STOP=1 -c \
    "SELECT d.id, d.installation_id, r.version, r.id FROM commercial.deployments d JOIN commercial.releases r ON r.id = d.current_release_id WHERE d.mode = 'dedicated' AND d.status IN ('active','suspended') AND d.archived_at IS NULL AND d.core_db_secret_ref = 'localdev://' || d.id::text || '/db' UNION ALL SELECT d.id,d.installation_id,r.version,r.id FROM commercial.deployments d JOIN commercial.releases r ON r.id=d.current_release_id JOIN commercial.deployment_operations o ON o.deployment_id=d.id WHERE d.mode='dedicated' AND d.status='provisioning' AND d.archived_at IS NULL AND d.core_db_secret_ref='localdev://' || d.id::text || '/db' AND o.operation_type='update' AND o.status IN ('running','retry') AND o.current_step='pre_update_backup' ORDER BY 1"
}
deployment_row() {
  local wanted=$1 rows id installation version release
  valid_id "$wanted" || die 'invalid deployment ID'
  rows=$(deployment_inventory) || die 'deployment inventory unavailable'
  while IFS='|' read -r id installation version release; do
    if [[ $id == "$wanted" ]]; then printf '%s|%s|%s|%s\n' "$id" "$installation" "$version" "$release"; return 0; fi
  done <<< "$rows"
  return 1
}
deployment_manifest_ok() {
  local expected=$1 manifest=$2 id backup_id path size hash
  valid_id "$expected" || return 1
  [[ -f $manifest && ! -L $manifest ]] || return 1
  jq -e --arg d "$expected" '(.schemaVersion == 1) and (.backupType == "deployment") and
    (.deploymentId == $d) and (.backupId|type == "string") and
    (.installationId|type == "string") and (.createdAt|type == "string") and
    (.applicationVersion|type == "string") and (.releaseId|type == "string") and
    (.dump|type == "object") and (.dump.file|type == "string") and
    (.dump.sizeBytes|type == "number" and . > 0) and
    (.dump.sha256|type == "string" and test("^[a-f0-9]{64}$")) and
    (.pgRestoreList == true)' "$manifest" >/dev/null || return 1
  id=$(jq -r .deploymentId "$manifest"); backup_id=$(jq -r .backupId "$manifest")
  valid_id "$id" && valid_id "$backup_id" || return 1
  valid_id "$(jq -r .installationId "$manifest")" || return 1
  valid_id "$(jq -r .releaseId "$manifest")" || return 1
  [[ $(jq -r .createdAt "$manifest") =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || return 1
  local created_epoch
  created_epoch=$(date -u -d "$(jq -r .createdAt "$manifest")" +%s 2>/dev/null) || return 1
  (( created_epoch <= $(date +%s) + 300 )) || return 1
  [[ $manifest == "deployments/$id/manifests/$backup_id.json" ]] || return 1
  path="deployments/$id/dumps/$backup_id.dump"
  [[ $(jq -r .dump.file "$manifest") == "$path" && -f $path && ! -L $path ]] || return 1
  size=$(stat -c %s "$path"); [[ $size == "$(jq -r .dump.sizeBytes "$manifest")" ]] || return 1
  hash=$(sha256sum "$path"); hash=${hash%% *}
  [[ $hash == "$(jq -r .dump.sha256 "$manifest")" ]] || return 1
  pg_restore --list "$path" >/dev/null
}
deployment_sorted() {
  local id=$1 f stamp
  valid_id "$id" || return 1
  for f in "deployments/$id/manifests/"*.json; do
    [[ -f $f && ! -L $f ]] || continue
    stamp=$(jq -r '.createdAt // empty' "$f" 2>/dev/null) || continue
    [[ $stamp =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || continue
    printf '%s %s\n' "$stamp" "$f"
  done | sort -r | cut -d' ' -f2-
}
deployment_latest() {
  local id=$1 f
  while IFS= read -r f; do
    if deployment_manifest_ok "$id" "$f"; then echo "$f"; return 0; fi
  done < <(deployment_sorted "$id")
  return 1
}
deployment_select() {
  local id=$1 name=${2:-}
  valid_id "$id" || die 'invalid deployment ID'
  if [[ -z $name ]]; then deployment_latest "$id" || die 'no verified deployment backup'; return; fi
  [[ $name =~ ^[0-9a-f-]+\.json$ ]] || die 'invalid manifest filename'
  valid_id "${name%.json}" || die 'invalid manifest ID'
  echo "deployments/$id/manifests/$name"
}
deployment_verify() {
  local manifest; manifest=$(deployment_select "$1" "${2:-}")
  deployment_manifest_ok "$1" "$manifest" || die 'deployment verification failed'
  echo "verified $manifest"
}
deployment_due() {
  local id=$1 latest stamp epoch final_epoch installation
  latest=$(deployment_latest "$id") || return 0
  stamp=$(jq -r .createdAt "$latest"); epoch=$(date -u -d "$stamp" +%s 2>/dev/null) || return 0
  final_epoch=$(deployment_final_epoch "$id") || return 0
  if [[ -n $final_epoch ]]; then
    installation=$(deployment_row "$id" | cut -d'|' -f2) || return 0
    [[ $(jq -r .installationId "$latest") == "$installation" && $epoch -ge $final_epoch ]] || return 0
  fi
  (( $(date +%s) - epoch >= ${DEPLOYMENT_BACKUP_INTERVAL_SECONDS:-86400} ))
}
deployment_final_epoch() {
  local id=$1
  valid_id "$id" || return 1
  db_config control
  psql -X -A -t -h "$dbhost" -U "$dbuser" -d "$dbname" -v ON_ERROR_STOP=1 -c \
    "SELECT ceil(extract(epoch from started_at))::bigint FROM commercial.deployment_operations WHERE deployment_id = '$id' AND operation_type = 'deprovision' AND status IN ('running','retry') AND current_step = 'final_backup' AND started_at IS NOT NULL ORDER BY created_at DESC LIMIT 1"
}
deployment_backup_purpose() {
  local id=$1 row
  db_config control
  row=$(psql -X -A -t -F '|' -h "$dbhost" -U "$dbuser" -d "$dbname" -v ON_ERROR_STOP=1 -c "SELECT CASE WHEN operation_type='update' THEN 'pre_update' ELSE 'decommission_final' END,id FROM commercial.deployment_operations WHERE deployment_id='$id' AND operation_type IN ('update','deprovision') AND status IN ('running','retry') AND current_step IN ('pre_update_backup','final_backup') ORDER BY created_at DESC LIMIT 1") || return 1
  [[ -n $row ]] && printf '%s\n' "$row"
}
deployment_backup() (
  local id=$1 row installation version release dir backup_id stamp dump size hash password_file purpose operation
  valid_id "$id" || die 'invalid deployment ID'
  row=$(deployment_row "$id") || die 'deployment is not eligible'
  IFS='|' read -r id installation version release <<< "$row"
  IFS='|' read -r purpose operation <<< "$(deployment_backup_purpose "$id" || true)"
  [[ -n $purpose ]] || purpose=periodic
  valid_id "$installation" && valid_id "$release" || die 'invalid deployment inventory'
  [[ $version =~ ^[a-zA-Z0-9._+-]{1,128}$ ]] || die 'invalid application version'
  password_file="/run/provisioning-secrets/$id/db_password"
  [[ -f $password_file && ! -L $password_file ]] || die 'deployment secret unavailable'
  dir=$(deployment_dir "$id"); mkdir -p "$dir/dumps" "$dir/manifests"
  exec 8>"$dir/backup.lock"; flock -n 8 || die 'deployment backup already running'
  backup_id=$(cat /proc/sys/kernel/random/uuid); valid_id "$backup_id" || die 'invalid backup ID'
  trap 'rc=$?; if ((rc != 0)); then rm -f -- "$dir/dumps/$backup_id.dump.partial" "$dir/dumps/$backup_id.dump" "$dir/manifests/$backup_id.json.partial" "$dir/manifests/$backup_id.json"; fi' EXIT
  stamp=$(now); dump="$dir/dumps/$backup_id.dump"
  export PGPASSWORD PGCONNECT_TIMEOUT=10; PGPASSWORD=$(<"$password_file")
  timeout 300 pg_dump -h "platform-deployment-${id//-/}-db" -U platform_runtime -d platform -Fc -f "$dump.partial"
  [[ -s $dump.partial ]] && pg_restore --list "$dump.partial" >/dev/null || die 'deployment dump failed'
  size=$(stat -c %s "$dump.partial"); hash=$(sha256sum "$dump.partial"); hash=${hash%% *}
  mv "$dump.partial" "$dump"
  jq -n --arg id "$backup_id" --arg d "$id" --arg i "$installation" --arg at "$stamp" \
    --arg v "$version" --arg r "$release" --arg p "$purpose" --arg o "$operation" --arg f "$dump" --arg h "$hash" --argjson s "$size" \
    '{schemaVersion:1,backupType:"deployment",backupId:$id,deploymentId:$d,installationId:$i,
      createdAt:$at,applicationVersion:$v,releaseId:$r,purpose:$p,operationId:($o|select(length>0)),dump:{file:$f,sizeBytes:$s,sha256:$h},pgRestoreList:true}' \
    > "$dir/manifests/$backup_id.json.partial"
  mv "$dir/manifests/$backup_id.json.partial" "$dir/manifests/$backup_id.json"
  deployment_manifest_ok "$id" "$dir/manifests/$backup_id.json" || die 'published deployment backup failed verification'
  trap - EXIT
  echo "deployment backup $id $backup_id verified"
  deployment_retention "$id"
)
deployment_retention() {
  local id=$1 f backup_id epoch cutoff count=0
  local -a good=()
  valid_id "$id" || die 'invalid deployment ID'
  cutoff=$(( $(date +%s) - ${DEPLOYMENT_BACKUP_RETENTION_DAYS:-30}*86400 ))
  while IFS= read -r f; do
    if deployment_manifest_ok "$id" "$f"; then good+=("$f"); fi
  done < <(deployment_sorted "$id")
  for f in "${good[@]}"; do
    ((count+=1)); (( count > ${DEPLOYMENT_BACKUP_MIN_SUCCESSFUL_SETS:-3} )) || continue
    epoch=$(date -u -d "$(jq -r .createdAt "$f")" +%s 2>/dev/null) || continue
    (( epoch < cutoff )) || continue
    deployment_manifest_ok "$id" "$f" || continue
    if [[ $(jq -r '.purpose // "periodic"' "$f") == pre_update ]]; then
      operation=$(jq -r '.operationId // empty' "$f")
      db_config control
      [[ $(psql -X -A -t -h "$dbhost" -U "$dbuser" -d "$dbname" -v ON_ERROR_STOP=1 -c "SELECT count(*) FROM commercial.deployment_operations WHERE id='$operation' AND status IN ('running','retry','failed')") == 1 ]] && continue
    fi
    backup_id=$(jq -r .backupId "$f")
    rm -f -- "deployments/$id/dumps/$backup_id.dump" "$f"
  done
}
deployment_batch() {
  local id installation version release rows count=0 failures=0
  rows=$(deployment_inventory) || die 'deployment inventory unavailable'
  # Operation-bound checkpoints are selected before normal interval backups.
  rows=$(printf '%s\n' "$rows" | awk -F'|' '{print $0}')
  while IFS='|' read -r id installation version release; do
    valid_id "$id" || continue
    if deployment_due "$id"; then
      if ! deployment_backup "$id"; then ((failures+=1)); fi
      ((count+=1))
      (( count < ${DEPLOYMENT_BACKUP_BATCH_SIZE:-2} )) || break
    fi
  done <<< "$rows"
  (( failures == 0 ))
}
deployment_health() {
  [[ ${DEPLOYMENT_BACKUP_ENABLED:-false} == true ]] || return 0
  local id installation version release latest stamp epoch rows bad=0
  rows=$(deployment_inventory) || return 1
  while IFS='|' read -r id installation version release; do
    valid_id "$id" || { bad=1; continue; }
    latest=$(deployment_latest "$id") || { bad=1; continue; }
    stamp=$(jq -r .createdAt "$latest"); epoch=$(date -u -d "$stamp" +%s 2>/dev/null) || { bad=1; continue; }
    (( $(date +%s) - epoch <= ${DEPLOYMENT_BACKUP_HEALTH_TIMEOUT_SECONDS:-172800} )) || bad=1
  done <<< "$rows"
  (( bad == 0 ))
}
deployment_status() {
  local id installation version release latest stamp epoch age state rows
  rows=$(deployment_inventory) || die 'deployment inventory unavailable'
  while IFS='|' read -r id installation version release; do
    valid_id "$id" || continue
    if latest=$(deployment_latest "$id"); then
      stamp=$(jq -r .createdAt "$latest"); epoch=$(date -u -d "$stamp" +%s 2>/dev/null) || epoch=0
      age=$(( $(date +%s) - epoch )); state=healthy
      (( age <= ${DEPLOYMENT_BACKUP_HEALTH_TIMEOUT_SECONDS:-172800} )) || state=stale
      jq -cn --arg id "$id" --arg status "$state" --arg at "$stamp" --argjson age "$age" \
        '{deploymentId:$id,status:$status,lastBackupAt:$at,backupAge:$age,verified:true}'
    else
      jq -cn --arg id "$id" '{deploymentId:$id,status:"missing",lastBackupAt:null,backupAge:null,verified:false}'
    fi
  done <<< "$rows"
}
deployment_drill() {
  local id=$1 manifest backup_id drill_db dbhost dbname dbuser expected actual rc=0
  manifest=$(deployment_select "$id" "${2:-}"); deployment_manifest_ok "$id" "$manifest" || die 'drill requires a verified deployment backup'
  expected=$(jq -r .installationId "$manifest"); backup_id=$(jq -r .backupId "$manifest")
  db_config core
  drill_db="platform_deployment_drill_${backup_id//-/}"
  createdb -h "$dbhost" -U "$dbuser" "$drill_db" || die 'cannot create deployment drill database'
  (
    trap 'exit_code=$?; trap - EXIT; dropdb -h "$dbhost" -U "$dbuser" --if-exists --force "$drill_db" || exit_code=1; exit "$exit_code"' EXIT
    pg_restore -h "$dbhost" -U "$dbuser" -d "$drill_db" --no-owner --no-privileges --exit-on-error "deployments/$id/dumps/$backup_id.dump" >/dev/null
    psql -X -A -t -h "$dbhost" -U "$dbuser" -d "$drill_db" -v ON_ERROR_STOP=1 -c \
      "SELECT (to_regclass('core.accounts') IS NOT NULL AND to_regclass('core.persons') IS NOT NULL AND to_regclass('core.organizations') IS NOT NULL AND to_regclass('core.privacy_destruction_evidence') IS NOT NULL AND to_regclass('platform_runtime.installations') IS NOT NULL AND to_regclass('platform_runtime.entitlement_documents') IS NOT NULL AND to_regclass('platform_runtime.background_job_status') IS NOT NULL AND EXISTS(SELECT 1 FROM public.platform_migrations WHERE migration_name = '031_platform_runtime_operational_status.sql'))::int" | grep -qx 1
    actual=$(psql -X -A -t -h "$dbhost" -U "$dbuser" -d "$drill_db" -v ON_ERROR_STOP=1 -c 'SELECT id FROM platform_runtime.installations')
    [[ $actual == "$expected" ]] || die 'deployment installation mismatch'
    echo "deployment restore drill passed $id"
  )
}
deployment_purge() {
  local id dir f name count=0 rows eligible
  [[ -n ${DECOMMISSIONED_DEPLOYMENT_BACKUP_RETENTION_DAYS:-} ]] || return 0
  [[ -d deployments && ! -L deployments ]] || die 'unsafe deployment backup root'
  db_config control
  rows=$(psql -X -A -t -h "$dbhost" -U "$dbuser" -d "$dbname" -v ON_ERROR_STOP=1 -c \
    "SELECT id FROM commercial.deployments WHERE mode = 'dedicated' AND status = 'decommissioned' AND archived_at IS NOT NULL AND archived_at <= now() - (${DECOMMISSIONED_DEPLOYMENT_BACKUP_RETENTION_DAYS} * interval '1 day') ORDER BY archived_at, id") || die 'purge inventory unavailable'
  while IFS= read -r id; do
    valid_id "$id" || continue
    dir="deployments/$id"
    [[ -d $dir || -L $dir ]] || continue
    [[ ! -L $dir && -d $dir ]] || die 'unsafe deployment backup directory'
    # Recheck the exact deployment before deleting any artifact.
    eligible=$(psql -X -A -t -h "$dbhost" -U "$dbuser" -d "$dbname" -v ON_ERROR_STOP=1 -c \
      "SELECT count(*) FROM commercial.deployments WHERE id = '$id' AND mode = 'dedicated' AND status = 'decommissioned' AND archived_at IS NOT NULL AND archived_at <= now() - (${DECOMMISSIONED_DEPLOYMENT_BACKUP_RETENTION_DAYS} * interval '1 day')") || die 'purge eligibility unavailable'
    [[ $eligible == 1 ]] || continue
    for name in dumps manifests; do
      [[ ! -L $dir/$name ]] || die 'unsafe deployment backup directory'
      [[ -d $dir/$name ]] || continue
      for f in "$dir/$name/"*; do
        [[ -e $f || -L $f ]] || continue
        [[ ! -L $f && -f $f ]] || die 'unsafe deployment backup artifact'
        if [[ $name == dumps ]]; then
          [[ ${f##*/} =~ ^[0-9a-f-]{36}\.dump(\.partial)?$ ]] || die 'unexpected deployment dump'
        else
          [[ ${f##*/} =~ ^[0-9a-f-]{36}\.json(\.partial)?$ ]] || die 'unexpected deployment manifest'
        fi
        rm -f -- "$f"
      done
      rmdir -- "$dir/$name"
    done
    [[ ! -L $dir/backup.lock ]] || die 'unsafe deployment backup lock'
    [[ ! -e $dir/backup.lock ]] || rm -f -- "$dir/backup.lock"
    rmdir -- "$dir"
    ((count+=1))
    (( count < ${DEPLOYMENT_BACKUP_BATCH_SIZE:-2} )) || break
  done <<< "$rows"
}
