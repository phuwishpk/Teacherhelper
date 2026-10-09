#!/usr/bin/env bash
# Sets the public address of the self-hosted deployment in app.env and
# restarts the app (docs/SELFHOST.md). The web app must then be built for the
# same address: tools/selfhost/push-web.sh on the developer's machine.
#   tools/selfhost/set-url.sh https://host[:port]
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
url="${1:-}"
url="${url%/}"
case "$url" in
  https://?*) ;;
  *) echo "usage: tools/selfhost/set-url.sh https://host[:port]" >&2; exit 1 ;;
esac

printf '%s\n' \
  "APP_URL=$url" \
  "GOOGLE_OAUTH_REDIRECT_URI=$url/google/oauth/callback" \
  "GOOGLE_SIGNIN_REDIRECT_URI=$url/auth/google/callback" \
  "GOOGLE_SIGNIN_APP_URL=$url/app" | "$HERE/set-env.sh"

echo "Redirect URIs the owner registers in Google Cloud Console (docs/HOSTING.md §6.3, §6.4):"
echo "  sign-in project:   $url/auth/google/callback"
echo "  Classroom project: $url/google/oauth/callback"
