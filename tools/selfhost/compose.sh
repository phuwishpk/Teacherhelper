#!/usr/bin/env bash
# docker compose for the self-hosted deployment (docs/SELFHOST.md), from any
# folder on the server:
#   tools/selfhost/compose.sh ps
#   tools/selfhost/compose.sh logs --tail 50 app
#   tools/selfhost/compose.sh exec -T -u www-data app php artisan migrate:status
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
EDUVISION_REPO="$(cd "$HERE/../.." && pwd)"
export EDUVISION_REPO

# The deployment folder is the parent of this clone: it holds app.env, .env and web/app.
exec docker compose --project-directory "$(dirname "$EDUVISION_REPO")" -f "$HERE/docker-compose.yml" "$@"
