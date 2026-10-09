#!/usr/bin/env bash
# The public address of the self-hosted deployment: a Cloudflare quick tunnel
# in a container next to the app (docs/SELFHOST.md). Opening the server to the
# internet is the owner's decision: run `up` only when they asked for it.
#   tools/selfhost/tunnel.sh up     start it and print its https://….trycloudflare.com address
#   tools/selfhost/tunnel.sh url    print the address of the running tunnel
#   tools/selfhost/tunnel.sh down   close public access
# The address is new every time the tunnel starts, and the tunnel does not
# come back by itself after a reboot: after `up`, run set-url.sh on the server
# and push-web.sh on the developer's machine with the new address.
set -euo pipefail

NAME="eduvision-tunnel"

address() {
    docker logs "$NAME" 2>&1 | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | head -1 || true
}

case "${1:-}" in
  up)
    if [ "$(docker inspect -f '{{.State.Running}}' "$NAME" 2> /dev/null || true)" != "true" ]; then
        docker rm -f "$NAME" > /dev/null 2>&1 || true
        docker run -d --name "$NAME" --memory 128m --network eduvision_default \
            cloudflare/cloudflared:latest tunnel --no-autoupdate --url http://app:80 > /dev/null
    fi
    url=""
    for _ in $(seq 1 30); do
        url="$(address)"
        [ -n "$url" ] && break
        sleep 2
    done
    [ -n "$url" ] || { echo "no address yet: docker logs $NAME" >&2; exit 1; }
    echo "$url"
    ;;
  url)
    url="$(address)"
    [ -n "$url" ] || { echo "the tunnel is not running" >&2; exit 1; }
    echo "$url"
    ;;
  down)
    docker rm -f "$NAME" > /dev/null 2>&1 || true
    echo "public access closed"
    ;;
  *)
    echo "usage: tools/selfhost/tunnel.sh up|url|down" >&2
    exit 1
    ;;
esac
