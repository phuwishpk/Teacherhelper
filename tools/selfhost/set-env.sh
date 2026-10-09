#!/usr/bin/env bash
# Sets KEY=VALUE lines read from stdin in the deployment's app.env (replaces
# the key's line, or adds it), then restarts the app and the worker so the
# config cache has them. Prints the key names only, never a value.
#   printf 'GEMINI_MODEL=gemini-3.8-flash\n' | tools/selfhost/set-env.sh
#   ... | tools/selfhost/set-env.sh --no-restart     # edit the file only
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(dirname "$(cd "$HERE/../.." && pwd)")"
ENV_FILE="$ROOT/app.env"

[ -f "$ENV_FILE" ] || { echo "missing $ENV_FILE (first install: tools/selfhost/install.sh)" >&2; exit 1; }

umask 077
lines="$(mktemp)"
new="$(mktemp "$ROOT/.app.env.XXXXXX")"
trap 'rm -f "$lines" "$new"' EXIT

grep -E '^[A-Z][A-Z0-9_]*=' > "$lines" || true
[ -s "$lines" ] || { echo "no KEY=VALUE lines on stdin" >&2; exit 1; }

awk -v kv="$lines" '
    BEGIN {
        while ((getline line < kv) > 0) {
            i = index(line, "=")
            k = substr(line, 1, i - 1)
            if (!(k in value)) order[++n] = k
            value[k] = substr(line, i + 1)
        }
    }
    {
        i = index($0, "=")
        k = i > 1 ? substr($0, 1, i - 1) : ""
        if (k in value) {
            if (!(k in seen)) print k "=" value[k]
            seen[k] = 1
        } else {
            print
        }
    }
    END {
        for (j = 1; j <= n; j++) if (!(order[j] in seen)) print order[j] "=" value[order[j]]
    }
' "$ENV_FILE" > "$new"

chmod 600 "$new"
mv "$new" "$ENV_FILE"
cut -d= -f1 "$lines" | sort -u | sed 's/^/set /'

if [ "${1:-}" != "--no-restart" ]; then
    "$HERE/compose.sh" restart app worker
fi
