#!/bin/sh
# Starts every Sprint container. The web container (the default command) checks the
# configuration, migrates the database and builds the caches; workers start right away.
set -e

# The storage volume is empty on the first start.
mkdir -p storage/app/private storage/database storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ -n "$DB_DATABASE" ] && [ ! -f "$DB_DATABASE" ]; then
    touch "$DB_DATABASE"
fi

if [ "$1" = "frankenphp" ]; then
    if [ -z "$APP_KEY" ]; then
        echo "APP_KEY is not set. Create one with: docker compose run --rm --no-deps sprint php artisan key:generate --show" >&2
        exit 1
    fi

    if [ "${MIGRATE_ON_START:-true}" = "true" ]; then
        php artisan migrate --force
    fi

    php artisan optimize
fi

exec "$@"
