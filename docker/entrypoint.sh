#!/bin/sh
set -eu

if [ "$#" -gt 0 ] && [ "$1" != "apache2-foreground" ]; then
    exec "$@"
fi

if [ -z "${APP_KEY:-}" ]; then
    echo "ERROR: APP_KEY es obligatoria. Configúrela en .env.docker." >&2
    exit 1
fi

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

php artisan config:cache
php artisan view:cache

exec "$@"
