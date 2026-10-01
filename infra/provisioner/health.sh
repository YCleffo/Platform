#!/usr/bin/env bash
set -Eeuo pipefail
[[ ${PROVISIONER_ENABLED:-false} == true ]] || exit 0
timeout=${PROVISIONER_HEALTH_TIMEOUT_SECONDS:-120}
[[ $timeout =~ ^[0-9]+$ ]] && (( 10#$timeout >= 30 && 10#$timeout <= 86400 )) || exit 1
file=/run/provisioning-secrets/status.json
[[ -f $file ]] || exit 1
stamp=$(jq -r '.heartbeatAt // empty' "$file")
[[ -n $stamp ]] || exit 1
age=$(( $(date +%s) - $(date -d "$stamp" +%s) ))
(( age >= 0 && age <= 10#$timeout ))
