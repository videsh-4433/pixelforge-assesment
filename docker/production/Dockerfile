# Production image for Render (or any container host). Single container running:
#   web (FrankenPHP) + queue worker + scheduler — see start.sh.

# ---- 1. PHP dependencies ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---- 2. Frontend assets (needs vendor/ for Ziggy's route() helper) ----
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- 3. Runtime ----
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_pgsql pgsql bcmath zip pcntl opcache intl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    # The base image grants frankenphp cap_net_bind_service (to bind ports < 1024). Hosts that
    # forbid file capabilities (e.g. Render) then refuse to exec it ("Operation not permitted").
    # We listen on an unprivileged port, so drop the capability.
    && setcap -r /usr/local/bin/frankenphp

WORKDIR /app
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/production/start.sh /usr/local/bin/start

RUN rm -f public/hot \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy \
    && chmod +x /usr/local/bin/start

USER www-data
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr PORT=10000
EXPOSE 10000

CMD ["start"]
