#!/bin/sh
set -eu

cd /app

missing=""
for name in \
    APP_KEY APP_URL \
    PIXELFORGE_API_KEY PIXELFORGE_WEBHOOK_SECRET \
    PUSHER_APP_ID PUSHER_APP_KEY PUSHER_APP_SECRET PUSHER_APP_CLUSTER
do
    eval "value=\${$name:-}"
    [ -n "$value" ] || missing="$missing $name"
done

if [ -z "${DB_URL:-}" ] && [ -z "${DB_HOST:-}" ]; then
    missing="$missing DB_HOST"
fi

if [ -n "$missing" ]; then
    echo "Missing required environment variables:$missing" >&2
    exit 1
fi

mkdir -p \
    storage/app/private storage/app/public \
    storage/framework/cache/data storage/framework/sessions storage/framework/views \
    storage/logs bootstrap/cache

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    attempt=1
    max_attempts="${MIGRATE_ATTEMPTS:-5}"

    until php artisan migrate --force; do
        if [ "$attempt" -ge "$max_attempts" ]; then
            echo "Migrations failed after $attempt attempts. Check the database connection settings." >&2
            exit 1
        fi

        attempt=$((attempt + 1))
        echo "Migration failed; retrying in 5s (attempt $attempt of $max_attempts)..." >&2
        sleep 5
    done
fi

exec "$@"
