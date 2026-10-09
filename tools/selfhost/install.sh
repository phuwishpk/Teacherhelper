#!/usr/bin/env bash
# First install on a server (docs/SELFHOST.md): writes app.env and .env with
# fresh keys and passwords, builds and starts the containers, creates the
# tables, the first school and the first admin. Refuses to run over an
# existing install. Prints no secret; the admin's first login goes to a
# private note for the owner.
#   tools/selfhost/install.sh admin@example.com
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
ROOT="$(dirname "$REPO")"

admin="${1:-}"
case "$admin" in
  ?*@?*.?*) ;;
  *) echo "usage: tools/selfhost/install.sh <email of the first admin>" >&2; exit 1 ;;
esac

if [ -e "$ROOT/app.env" ] || [ -e "$ROOT/.env" ]; then
    echo "already installed in $ROOT: update with tools/selfhost/deploy.sh" >&2
    exit 1
fi
if docker volume inspect eduvision_db > /dev/null 2>&1; then
    # A new app.env would hold another database password and APP_KEY.
    echo "the database volume eduvision_db exists but app.env/.env are gone:" >&2
    echo "restore both files from the owner's copy (docs/SELFHOST.md), do not reinstall over the data" >&2
    exit 1
fi

# The web app folder is served by the container's www-data: world-readable.
mkdir -p "$ROOT/web/app"
chmod 755 "$ROOT/web" "$ROOT/web/app"
umask 077

db_password="$(openssl rand -hex 16)"
printf 'DB_PASSWORD=%s\nDB_ROOT_PASSWORD=%s\n' "$db_password" "$(openssl rand -hex 16)" > "$ROOT/.env"

# backend/.env.example lists every variable; these lines are what production changes.
cp "$REPO/backend/.env.example" "$ROOT/app.env"
chmod 600 "$ROOT/app.env" "$ROOT/.env"
{
    echo "APP_ENV=production"
    echo "APP_KEY=base64:$(openssl rand -base64 32)"
    echo "APP_DEBUG=false"
    echo "APP_URL=http://127.0.0.1:8085"
    echo "LOG_CHANNEL=daily"
    echo "LOG_LEVEL=info"
    echo "LOG_DAILY_DAYS=14"
    echo "DB_HOST=db"
    echo "DB_PORT=3306"
    echo "DB_DATABASE=eduvision"
    echo "DB_USERNAME=eduvision"
    echo "DB_PASSWORD=$db_password"
    echo "SESSION_SECURE_COOKIE=true"
    echo "SEED_TEACHER_JOIN_CODE=$(openssl rand -hex 4 | tr 'a-f' 'A-F')"
    echo "ADMIN_EMAIL=$admin"
    echo "ADMIN_PASSWORD=$(openssl rand -hex 10)"
    echo "QR_SIGNING_KEY=$(openssl rand -hex 32)"
    echo "GEMINI_FAKE=false"
    echo "GOOGLE_OAUTH_REDIRECT_URI="
} | "$HERE/set-env.sh" --no-restart > /dev/null

"$HERE/deploy.sh" --no-pull

artisan() { "$HERE/compose.sh" exec -T -u www-data app php artisan "$@"; }
artisan db:seed --class=SchoolSeeder --force
artisan db:seed --class=AdminSeeder --force

# The first admin login moves to a note for the owner, and the running config forgets it.
grep -E '^ADMIN_(EMAIL|PASSWORD)=' "$ROOT/app.env" > "$ROOT/admin-first-login.txt"
printf 'ADMIN_EMAIL=\nADMIN_PASSWORD=\n' | "$HERE/set-env.sh" > /dev/null

echo "installed in $ROOT. The first admin login is in $ROOT/admin-first-login.txt:"
echo "it is for the owner to read (do not print it); they change the password in /admin and delete the file."
echo "Next: a public address (tools/selfhost/tunnel.sh, only when the owner asks), then set-url.sh and push-web.sh."
