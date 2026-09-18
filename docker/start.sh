#!/bin/sh
set -e

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force

# Public demo account (only when DEMO_ENABLED=true): reset ITS data at boot
# and keep the scheduler running in the background, which re-runs the reset
# every 3 hours. demo:reset only ever touches the demo user's own rows.
if [ "${DEMO_ENABLED}" = "true" ]; then
    php artisan demo:reset || echo "demo:reset failed, continuing boot"
    php artisan schedule:work >> storage/logs/scheduler.log 2>&1 &
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
