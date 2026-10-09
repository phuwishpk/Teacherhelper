#!/usr/bin/env bash
# Builds the web app for the self-hosted deployment's address and copies it to
# the server (docs/SELFHOST.md). Run it on the developer's machine, from the
# commit that is deployed on the server.
#   tools/selfhost/push-web.sh https://host[:port]
#   SELFHOST_SSH=other-alias tools/selfhost/push-web.sh https://host[:port]
set -euo pipefail

url="${1:-}"
url="${url%/}"
case "$url" in
  https://?*) ;;
  *) echo "usage: tools/selfhost/push-web.sh https://host[:port]" >&2; exit 1 ;;
esac

HOST="${SELFHOST_SSH:-phuwish115}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="$(mktemp -d)"
trap 'rm -rf "$OUT"' EXIT

OUT_DIR="$OUT" "$ROOT/tools/build-web.sh" "$url"
rsync -a --delete "$OUT/app/" "$HOST:eduvision/web/app/"

echo "web app on the server: commit $(curl -fsS -m 20 "$url/app/build-commit.txt")"
