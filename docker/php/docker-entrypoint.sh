#!/bin/sh
set -e

cd /var/www/html

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ] && [ -f artisan ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
