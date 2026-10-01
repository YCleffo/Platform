#!/usr/bin/env bash
set -Eeuo pipefail
repo=$(cd "$(dirname "$0")/../.." && pwd -P)
test_root=$(mktemp -d "$repo/.phase55-test.XXXXXX")
trap '[[ $test_root == "$repo"/.phase55-test.* ]] && rm -rf -- "$test_root"' EXIT
mkdir -p "$test_root/bin"
cat > "$test_root/bin/pg_restore" <<'EOF'
#!/usr/bin/env bash
[[ $1 == --list && -s $2 ]]
EOF
chmod +x "$test_root/bin/pg_restore"
export PATH="$test_root/bin:$PATH"
cd "$test_root"
die() { echo "$*" >&2; exit 1; }
valid_id() { [[ $1 =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]]; }
now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
source "$repo/infra/backup/deployment.sh"
deployment_final_epoch() { printf '%s\n' "${test_final_epoch:-}"; }
a=11111111-1111-4111-8111-111111111111
b=22222222-2222-4222-8222-222222222222
installation=33333333-3333-4333-8333-333333333333
release=44444444-4444-4444-8444-444444444444
make_set() {
  local id=$1 backup_id=$2 stamp=$3 dir="deployments/$1" hash size
  mkdir -p "$dir/dumps" "$dir/manifests"
  printf 'synthetic archive %s\n' "$backup_id" > "$dir/dumps/$backup_id.dump"
  size=$(stat -c %s "$dir/dumps/$backup_id.dump")
  hash=$(sha256sum "$dir/dumps/$backup_id.dump"); hash=${hash%% *}
  jq -n --arg d "$id" --arg id "$backup_id" --arg i "$installation" --arg r "$release" --arg at "$stamp" \
    --arg f "$dir/dumps/$backup_id.dump" --arg h "$hash" --argjson s "$size" \
    '{schemaVersion:1,backupType:"deployment",backupId:$id,deploymentId:$d,installationId:$i,
    createdAt:$at,applicationVersion:"5.5.0",releaseId:$r,dump:{file:$f,sizeBytes:$s,sha256:$h},pgRestoreList:true}' \
    > "$dir/manifests/$backup_id.json"
}
one=00000000-0000-4000-8000-000000000001
two=00000000-0000-4000-8000-000000000002
three=00000000-0000-4000-8000-000000000003
make_set "$a" "$one" 2020-01-01T00:00:00Z
make_set "$a" "$two" 2020-01-02T00:00:00Z
make_set "$a" "$three" 2020-01-03T00:00:00Z
make_set "$b" "$one" 2020-01-01T00:00:00Z
deployment_manifest_ok "$a" "deployments/$a/manifests/$one.json"
jq '.schemaVersion=2' "deployments/$a/manifests/$one.json" > invalid.tmp
mv "deployments/$a/manifests/$one.json" original.tmp
mv invalid.tmp "deployments/$a/manifests/$one.json"
if deployment_manifest_ok "$a" "deployments/$a/manifests/$one.json"; then die 'invalid schema accepted'; fi
mv original.tmp "deployments/$a/manifests/$one.json"
if deployment_manifest_ok "$b" "deployments/$a/manifests/$one.json"; then die 'wrong deployment accepted'; fi
printf x >> "deployments/$a/dumps/$one.dump"
if deployment_manifest_ok "$a" "deployments/$a/manifests/$one.json"; then die 'SHA mismatch accepted'; fi
printf 'synthetic archive %s\n' "$one" > "deployments/$a/dumps/$one.dump"
mv "deployments/$a/dumps/$one.dump" "deployments/$a/dumps/$one.missing"
if deployment_manifest_ok "$a" "deployments/$a/manifests/$one.json"; then die 'missing dump accepted'; fi
mv "deployments/$a/dumps/$one.missing" "deployments/$a/dumps/$one.dump"
cp "deployments/$a/manifests/$three.json" "deployments/$a/manifests/$three.json.partial"
[[ $(deployment_latest "$a") == "deployments/$a/manifests/$three.json" ]] || die 'latest selection failed'
export DEPLOYMENT_BACKUP_INTERVAL_SECONDS=60
deployment_due "$a" || die 'old backup not due'
jq --arg at "$(now)" '.createdAt=$at' "deployments/$a/manifests/$three.json" > latest.tmp
mv latest.tmp "deployments/$a/manifests/$three.json"
if deployment_due "$a"; then die 'fresh backup is due'; fi
test_installation=$installation
deployment_row() { printf '%s|%s|5.5.0|%s\n' "$a" "$test_installation" "$release"; }
test_final_epoch=$(( $(date +%s) + 1 ))
deployment_due "$a" || die 'pre-request backup satisfied final request'
jq --arg at "$(date -u -d '+2 seconds' +%Y-%m-%dT%H:%M:%SZ)" '.createdAt=$at' \
  "deployments/$a/manifests/$three.json" > latest.tmp
mv latest.tmp "deployments/$a/manifests/$three.json"
if deployment_due "$a"; then die 'final backup remains due after a newer verified manifest'; fi
export DEPLOYMENT_BACKUP_RETENTION_DAYS=1 DEPLOYMENT_BACKUP_MIN_SUCCESSFUL_SETS=2
deployment_retention "$a"
[[ ! -e "deployments/$a/manifests/$one.json" && -e "deployments/$a/manifests/$two.json" && -e "deployments/$a/manifests/$three.json" ]] || die 'retention or latest protection failed'
[[ -e "deployments/$b/manifests/$one.json" ]] || die 'retention crossed deployment boundary'
deployment_purge
[[ -d "deployments/$a" ]] || die 'disabled purge removed backup chain'
cat > "$test_root/bin/psql" <<EOF
#!/usr/bin/env bash
case "\$*" in
  *'SELECT id FROM commercial.deployments'*) echo '$a' ;;
  *'SELECT count(*) FROM commercial.deployments'*) echo 1 ;;
  *) exit 1 ;;
esac
EOF
chmod +x "$test_root/bin/psql"
db_config() { dbhost=local; dbuser=local; dbname=local; }
export DECOMMISSIONED_DEPLOYMENT_BACKUP_RETENTION_DAYS=1
deployment_purge
[[ ! -e "deployments/$a" && -f "deployments/$b/manifests/$one.json" ]] || die 'purge crossed deployment boundary'
echo 'deployment manifest, identity, SHA, missing dump, partial, final due, retention and purge tests passed'
