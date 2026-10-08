# syntax=docker/dockerfile:1

# Sprint as a single image: FrankenPHP (Caddy + PHP) serves the app; the same image runs
# the queue worker, the scheduler and Reverb (see compose.yaml and docs/deployment-docker.md).

ARG PHP_VERSION=8.4

FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm AS base

RUN install-php-extensions intl opcache pcntl pdo_mysql pdo_pgsql zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-sprint.ini"

WORKDIR /app

# PHP dependencies; the Flux Pro credentials come in as a build secret (an auth.json) and never end up in a layer.
FROM base AS vendor

COPY composer.json composer.lock ./

RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    --mount=type=secret,id=composer_auth,target=/app/auth.json,required=true \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# Front-end assets; Tailwind reads the Flux styles and Blade views from vendor.
FROM node:25-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci --ignore-scripts

COPY vite.config.js ./
COPY resources ./resources
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

FROM base AS app

ARG USER=sprint

RUN useradd --create-home --uid 1000 "$USER" \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R "$USER:$USER" /config/caddy /data/caddy

COPY --chown=$USER:$USER --from=vendor /app/vendor ./vendor
COPY --chown=$USER:$USER . .
COPY --chown=$USER:$USER --from=assets /app/public/build ./public/build

RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    mkdir -p bootstrap/cache storage/app/private storage/database storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R "$USER:$USER" bootstrap/cache storage \
    && su "$USER" -c "composer dump-autoload --optimize --no-dev --no-interaction"

USER $USER

ENV SERVER_NAME=:80 \
    DB_DATABASE=/app/storage/database/database.sqlite

VOLUME /app/storage

# For every container (the base image would check Caddy, which only runs in the web container):
# database, storage and cache must work; compose.yaml checks Reverb's port instead.
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --start-interval=3s CMD ["php", "artisan", "sprint:health"]

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
