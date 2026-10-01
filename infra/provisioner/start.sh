#!/usr/bin/env bash
set -Eeuo pipefail
mount_source=$(docker inspect "$HOSTNAME" --format '{{range .Mounts}}{{if eq .Destination "/run/provisioning-secrets"}}{{.Source}}{{end}}{{end}}')
[[ -n "$mount_source" ]] || { echo 'Provisioning secret mount unavailable' >&2; exit 1; }
export PROVISIONER_SECRET_HOST_DIR="$mount_source"
exec php /app/bin/console provisioner:run "$@"
