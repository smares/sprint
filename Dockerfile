# syntax=mirror.gcr.io/docker/dockerfile:1@sha256:4edf897a3ffa55b89f906fc8cc78afdb3f1834cc9c7083565e611a8a7d5fe99e

# Sprint as a single image: FrankenPHP (Caddy + PHP) serves the app; the same image runs
# the queue worker, the scheduler and Reverb (see compose.yaml and docs/deployment-docker.md).

# Docker Hub images come through Google's mirror (mirror.gcr.io): Docker Hub limits anonymous pulls and has been
# down during releases; the mirror serves the same images. Every image is pinned by digest, so a build uses exactly the
# image that was tested; Dependabot proposes new digests for the FROM lines (the tag says which line they follow). The digests of the
# syntax frontend here and of BuildKit in .github/workflows/release.yml are updated by hand.

# The Composer binary comes from its own pinned stage, so the vendor stage (which holds the Flux Pro credentials) runs nothing unpinned
FROM mirror.gcr.io/library/composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer

FROM mirror.gcr.io/dunglas/frankenphp:1-php8.4-bookworm@sha256:49369ff211d81dc30a15890d58c0651d639aef0d9c0a9b91bcc6f387a77bd7a2 AS base

RUN install-php-extensions gmp intl opcache pcntl pdo_mysql pdo_pgsql zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-sprint.ini"

WORKDIR /app

# PHP dependencies; the Flux Pro credentials come in as a build secret (COMPOSER_AUTH, which Composer reads) and never end up in a layer.
FROM base AS vendor

COPY composer.json composer.lock ./

RUN --mount=type=bind,from=composer,source=/usr/bin/composer,target=/usr/bin/composer \
    --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH,required=true \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# Front-end assets; Tailwind reads the Flux styles and Blade views from vendor.
FROM mirror.gcr.io/library/node:26-bookworm-slim@sha256:28147155d47dc31abe0a46e0d12b59b2fb3d0a2dcc5dc65fce76a074180b16fe AS assets

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

RUN --mount=type=bind,from=composer,source=/usr/bin/composer,target=/usr/bin/composer \
    mkdir -p bootstrap/cache storage/app/private storage/database storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R "$USER:$USER" bootstrap/cache storage \
    && su "$USER" -c "composer dump-autoload --optimize --no-dev --no-interaction"

USER $USER

# The release workflow passes the version; it is shown in the user menu
ARG APP_VERSION=dev

ENV APP_VERSION=$APP_VERSION \
    SERVER_NAME=:80 \
    DB_DATABASE=/app/storage/database/database.sqlite

VOLUME /app/storage

# For every container (the base image would check Caddy, which only runs in the web container):
# database, storage and cache must work; compose.yaml checks Reverb's port instead.
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --start-interval=3s CMD ["php", "artisan", "sprint:health"]

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
