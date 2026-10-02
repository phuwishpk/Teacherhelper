#!/usr/bin/env bash
# Builds the Flutter web app for <API_BASE_URL>/app/ and zips it for the Plesk
# File Manager (docs/HOSTING.md §4.9, DESIGN §25).
#
#   tools/build-web.sh                        # https://teacherhelper.phuwish.com
#   tools/build-web.sh https://example.org    # another server
#   OUT_DIR=/tmp/web tools/build-web.sh       # where the folder and zip go
#
# Result: $OUT_DIR/app/ (the site) and $OUT_DIR/eduvision-web.zip. Upload the
# zip to <deployment path>/backend/public/ and extract it there, so the files
# end up in backend/public/app/. Nothing here is secret: the build holds the
# API address only.
set -euo pipefail

API_BASE_URL="${1:-https://teacherhelper.phuwish.com}"
API_BASE_URL="${API_BASE_URL%/}"
OUT_DIR="${OUT_DIR:-$HOME/eduvision-deploy}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

case "$API_BASE_URL" in
  https://*) ;;
  http://127.0.0.1:* | http://localhost:*) echo "note: building for a local server ($API_BASE_URL)" >&2 ;;
  *) echo "API_BASE_URL must start with https:// (got $API_BASE_URL)" >&2; exit 1 ;;
esac

# The page talks to its own server ('self') in production. A local build
# names the API origin, because the test page is served from another port.
CONNECT_SRC="'self' https://fonts.gstatic.com"
case "$API_BASE_URL" in http://*) CONNECT_SRC="$CONNECT_SRC $API_BASE_URL" ;; esac

mkdir -p "$OUT_DIR"
SITE="$OUT_DIR/app"
rm -rf "$SITE" "$OUT_DIR/eduvision-web.zip"

cd "$ROOT/app"
flutter pub get
# --no-web-resources-cdn: CanvasKit comes from this server, not from gstatic.com.
flutter build web --release \
  --base-href /app/ \
  --no-web-resources-cdn \
  --dart-define=API_BASE_URL="$API_BASE_URL" \
  -o "$SITE"

# The web server serves these files itself, so Laravel's SecurityHeaders
# middleware never sees them: the same protections go into an .htaccess.
# - script-src 'wasm-unsafe-eval': CanvasKit is WebAssembly;
# - fonts.gstatic.com: Flutter fetches Roboto and Noto Sans Thai from there;
# - no-cache on the entry files, so a new upload shows up on the next reload.
cat > "$SITE/.htaccess" <<EOF
# Written by tools/build-web.sh; do not edit on the server.
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "no-referrer"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' https://fonts.gstatic.com; connect-src $CONNECT_SRC; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
    <FilesMatch "^(index\.html|flutter_bootstrap\.js|flutter_service_worker\.js|main\.dart\.js|version\.json|manifest\.json)$">
        Header set Cache-Control "no-cache"
    </FilesMatch>
</IfModule>
Options -Indexes
EOF

git -C "$ROOT" rev-parse --short HEAD > "$SITE/build-commit.txt" 2>/dev/null || true

(cd "$OUT_DIR" && zip -qr eduvision-web.zip app)
echo
echo "Built for $API_BASE_URL/app/"
echo "  folder: $SITE"
echo "  zip:    $OUT_DIR/eduvision-web.zip ($(du -h "$OUT_DIR/eduvision-web.zip" | cut -f1))"
echo "Upload the zip to <deployment path>/backend/public/ in Plesk > Files and extract it there."
