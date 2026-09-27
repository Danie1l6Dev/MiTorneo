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

# "php artisan serve" stays internal-only (127.0.0.1:8000, never exposed
# outside the container). Caddy is what actually listens on $PORT: it serves
# /build and /assets directly (gzip/zstd + far-future cache headers) and
# reverse-proxies everything else here, unchanged. See docker/Caddyfile.
php artisan serve --host=127.0.0.1 --port=8000 &
ARTISAN_PID=$!

# Don't let Caddy (and therefore real traffic, including Render's own health
# check) start until artisan serve is actually listening -- otherwise a
# request landing in that split-second gap gets a 502. Gives up after ~5s
# and starts Caddy anyway rather than hanging forever if something's wrong.
tries=0
until php -r 'exit(@fsockopen("127.0.0.1", 8000) ? 0 : 1);' || [ "$tries" -ge 25 ]; do
    tries=$((tries + 1))
    sleep 0.2
done

caddy run --config /etc/caddy/Caddyfile --adapter caddyfile &
CADDY_PID=$!

cleanup() {
    kill "$ARTISAN_PID" "$CADDY_PID" 2>/dev/null
    wait "$ARTISAN_PID" "$CADDY_PID" 2>/dev/null
    exit 0
}
trap cleanup INT TERM

wait "$CADDY_PID"
cleanup
