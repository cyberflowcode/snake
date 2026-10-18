#!/usr/bin/env bash
set -e

SERVICE_MODE="${1:-web}"

echo "🚀 Starting Snake Arena Service in mode: [${SERVICE_MODE}]"

# Wait for MySQL connection if configured
if [ -n "$DB_HOST" ]; then
    echo "Checking database connection to $DB_HOST:$DB_PORT..."
    for i in {1..30}; do
        if nc -z "$DB_HOST" "${DB_PORT:-3306}" 2>/dev/null; then
            echo "Database connection ready."
            break
        fi
        echo "Waiting for database ($i/30)..."
        sleep 2
    done
fi

if [ "$SERVICE_MODE" = "web" ]; then
    echo "Running migrations..."
    php artisan migrate --force --no-interaction || true

    echo "Optimizing route and config caches..."
    php artisan config:cache || true
    php artisan route:cache || true

    echo "Serving application on port ${PORT:-8080}..."
    exec php -S 0.0.0.0:"${PORT:-8080}" -t public server.php

elif [ "$SERVICE_MODE" = "reverb" ]; then
    echo "Starting Laravel Reverb WebSocket server on port ${PORT:-8080}..."
    exec php artisan reverb:start --host=0.0.0.0 --port="${PORT:-8080}"

elif [ "$SERVICE_MODE" = "worker" ]; then
    echo "Starting dedicated Snake Game Loop Worker (${GAME_TICK_RATE:-10} ticks/sec)..."
    exec php artisan snake:game-loop --fps="${GAME_TICK_RATE:-10}"

else
    echo "Executing custom command: $@"
    exec "$@"
fi
