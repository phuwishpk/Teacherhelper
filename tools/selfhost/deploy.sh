#!/usr/bin/env bash
# Updates the self-hosted deployment to the newest commit of this clone's
# branch: pull, build the image, restart the containers, migrate
# (docs/SELFHOST.md). Run it on the server.
#   tools/selfhost/deploy.sh             # git pull first
#   tools/selfhost/deploy.sh --no-pull   # deploy the checked-out commit
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
ROOT="$(dirname "$REPO")"
HEALTH="http://127.0.0.1:8085/api/v1/health"

compose() { "$HERE/compose.sh" "$@"; }

# In a function, so a pull that changes this file cannot change a running deploy.
main() {
    if [ ! -f "$ROOT/app.env" ] || [ ! -f "$ROOT/.env" ]; then
        echo "no app.env/.env in $ROOT: first install is tools/selfhost/install.sh (docs/SELFHOST.md)" >&2
        exit 1
    fi

    if [ "${1:-}" != "--no-pull" ]; then
        git -C "$REPO" pull --ff-only
    fi

    mkdir -p "$ROOT/web/app"
    compose build app
    compose up -d --remove-orphans

    # Any HTTP answer will do here: before the first migrate the health check is a 500.
    local code="000"
    for _ in $(seq 1 60); do
        code="$(curl -s -o /dev/null -m 3 -w '%{http_code}' "$HEALTH" || true)"
        [ "$code" != "000" ] && break
        sleep 2
    done
    [ "$code" != "000" ] || { echo "the app did not start: tools/selfhost/compose.sh logs app" >&2; exit 1; }

    compose exec -T -u www-data app php artisan migrate --force

    local health
    health="$(curl -s -m 10 "$HEALTH" || true)"
    echo "deployed $(git -C "$REPO" rev-parse --short HEAD): $health"
    case "$health" in
      *'"db":"ok"'*) ;;
      *) echo "the health check does not report the database as ok" >&2; exit 1 ;;
    esac
}

main "$@"
