# syntax=docker/dockerfile:1.7

ARG FRANKENPHP_VERSION=1.13.5
ARG PHP_VERSION=8.3

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-alpine AS base
RUN install-php-extensions pdo_pgsql bcmath pcntl opcache zip

FROM base AS build
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN apk add --no-cache nodejs npm git unzip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# Public values only: they are compiled into the JavaScript bundle.
ARG VITE_APP_NAME="Laravel"
ARG VITE_PUSHER_APP_KEY
ARG VITE_PUSHER_APP_CLUSTER
ARG VITE_PUSHER_HOST=""
ARG VITE_PUSHER_PORT=443
ARG VITE_PUSHER_SCHEME=https
ENV VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_PUSHER_APP_KEY=${VITE_PUSHER_APP_KEY} \
    VITE_PUSHER_APP_CLUSTER=${VITE_PUSHER_APP_CLUSTER} \
    VITE_PUSHER_HOST=${VITE_PUSHER_HOST} \
    VITE_PUSHER_PORT=${VITE_PUSHER_PORT} \
    VITE_PUSHER_SCHEME=${VITE_PUSHER_SCHEME}

RUN APP_ENV=production APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" \
    php artisan wayfinder:generate --with-form \
    && APP_ENV=production APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" npm run build

FROM base AS runtime

RUN apk add --no-cache --virtual .caps libcap-utils \
    && (setcap -r /usr/local/bin/frankenphp 2>/dev/null || true) \
    && apk del .caps \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/Caddyfile /etc/caddy/Caddyfile

WORKDIR /app
COPY . .
COPY --from=build /app/vendor ./vendor
COPY --from=build /app/public/build ./public/build

RUN addgroup -g 1000 app && adduser -u 1000 -G app -D -H app \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache \
    && chmod 0755 docker/entrypoint.sh

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    XDG_CONFIG_HOME=/tmp/caddy-config \
    XDG_DATA_HOME=/tmp/caddy-data \
    PORT=8080

USER app
EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=5s --start-period=60s --retries=3 \
    CMD wget -qO- "http://127.0.0.1:${PORT}/up" >/dev/null || exit 1

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
