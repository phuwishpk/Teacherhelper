#!/bin/sh
# The two Scheduled Tasks of the Plesk host (docs/HOSTING.md §4.7) as a loop:
# one bounded queue pass, a pause, and the image purge once a night at
# 02:00 Asia/Bangkok (19:00 UTC). Runs as www-data in the worker container.
cd /var/www/html || exit 1
purged=""
while true; do
    php artisan eduvision:queue-work > /dev/null || true
    if [ "$(date -u +%H)" = "19" ] && [ "$(date -u +%F)" != "$purged" ]; then
        php artisan eduvision:purge-images || true
        purged="$(date -u +%F)"
    fi
    sleep 30
done
