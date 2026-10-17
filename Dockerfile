# syntax=docker/dockerfile:1
#
# SpendSense backend - production image for Render (Docker runtime).
# Laravel 12 | PHP 8.3 (satisfies "^8.2") | PHP-FPM + Nginx | PostgreSQL
#
# pdo_pgsql is compiled against Debian bookworm's libpq (>= 14) and links the
# matching libpq5 at runtime.
#
# Design notes:
# - One container, two processes: Nginx listens on the platform-provided $PORT
#   and proxies to PHP-FPM on 127.0.0.1:9000. If either process exits, the
#   entrypoint ends and the platform restarts the instance.
# - No .env is baked into the image: configuration comes from environment
#   variables (Render dashboard). PHP-FPM is configured below to pass them
#   through to workers (clear_env = no).
# - No migrations run here or at container start: the schema was applied to
#   Neon as a separate, deliberate step. Future schema changes use new
#   migrations applied explicitly.
# - Route caching is intentionally omitted for simplicity (Laravel 12 can
#   cache closure routes via SerializableClosure; revisit if request
#   performance needs it).
# - Data lives in Neon; logs go to stderr, sessions/cache to the database.
#   The container filesystem is treated as ephemeral.

# ---------- Stage 1: application dependencies ----------
FROM php:8.3-cli-bookworm AS builder

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# .env, vendor/, tests/ and local caches/logs are excluded via .dockerignore,
# so the autoloader and package manifest are regenerated fresh here.
COPY . .

RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --no-progress

# ---------- Stage 2: runtime (Nginx + PHP-FPM) ----------
FROM php:8.3-fpm-bookworm AS runtime

ENV PORT=10000

# Nginx serves the public edge; envsubst (gettext-base) renders the $PORT
# template; libpq5 is the runtime lib for pdo_pgsql, while libpq-dev is only
# needed to compile the extension and is removed afterwards.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends nginx gettext-base libpq5 libpq-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql; \
    apt-get purge -y --auto-remove libpq-dev; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# Pass platform environment variables (Render) through to PHP-FPM workers.
# Laravel reads its configuration from the environment; there is no .env file.
RUN printf '[www]\nclear_env = no\n' > /usr/local/etc/php-fpm.d/zz-env.conf

# OPcache ships enabled in the php:8.3-fpm base image; this adds settings only.
COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

# Nginx: drop the distro default site; ship the $PORT-aware template instead.
RUN rm -f /etc/nginx/sites-enabled/default
COPY docker/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# Application plus composer artifacts from the builder stage.
COPY --from=builder /app /var/www/html

RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Informational; the effective port comes from $PORT (Render default: 10000).
EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
