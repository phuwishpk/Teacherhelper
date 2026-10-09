#!/bin/sh
# Starts an Krucheck container: installs the mounted .env, makes the storage
# volume writable, rebuilds Laravel's caches (web container only), then hands
# over to the command.
set -e
cd /var/www/html

# The host keeps app.env private (mode 600), so copy it in as www-data's file.
if [ -f /run/eduvision/app.env ]; then
    install -o www-data -g www-data -m 600 /run/eduvision/app.env .env
fi
[ -f .env ] || { echo "eduvision: /run/eduvision/app.env is missing" >&2; exit 1; }

mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
         storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

if [ "$1" = "apache2-foreground" ]; then
    # Production reads the config cache: rebuild it from .env on every start.
    runuser -u www-data -- php artisan optimize
    runuser -u www-data -- php artisan filament:optimize
fi

exec docker-php-entrypoint "$@"
