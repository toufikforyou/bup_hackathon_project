#!/bin/sh
set -e

if [ -z "${APP_KEY}" ]; then
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
    export APP_KEY
fi

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

exec frankenphp run "$@"
