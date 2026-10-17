#!/usr/bin/env bash
#
# SpendSense backend - container entrypoint (Render / Docker).
#
# 1. Renders the Nginx site for the platform-provided $PORT.
# 2. Ensures writable Laravel paths (the container filesystem is ephemeral).
# 3. Builds the Laravel config and view caches for this environment.
# 4. Runs PHP-FPM + Nginx together; if either exits, the container exits so
#    the platform can restart it.
#
# Deliberately NOT done here: database migrations. The schema was applied to
# Neon as a separate deliberate step; future schema changes must be applied
# explicitly too.
#
set -euo pipefail

export PORT="${PORT:-10000}"

envsubst '${PORT}' \
    < /etc/nginx/templates/default.conf.template \
    > /etc/nginx/conf.d/default.conf

cd /var/www/html

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Cache configuration and compiled views for THIS environment.
# Route caching is intentionally omitted for simplicity (Laravel 12 can cache
# closure routes via SerializableClosure; revisit if request speed needs it).
if [ -z "${APP_KEY:-}" ]; then
    echo "FATAL: APP_KEY is not set. Set it in the host's environment settings." >&2
    exit 1
fi
php artisan config:cache --no-interaction
php artisan view:cache --no-interaction

php-fpm -F &
fpm_pid=$!

nginx -g 'daemon off;' &
nginx_pid=$!

# Graceful shutdown: TERM/INT/QUIT all forward SIGQUIT to both children
# (php-fpm and nginx drain in-flight requests on QUIT), then wait for them.
# The base image inherits STOPSIGNAL SIGQUIT from php:8.3-fpm, so a plain
# `docker stop` also takes this path.
trap 'kill -QUIT "$fpm_pid" "$nginx_pid" 2>/dev/null || true; wait || true; exit 0' TERM INT QUIT

wait -n "$fpm_pid" "$nginx_pid" || true

# One of the processes exited unexpectedly: bring the other down and fail so
# the platform restarts the instance.
kill "$fpm_pid" "$nginx_pid" 2>/dev/null || true
exit 1
