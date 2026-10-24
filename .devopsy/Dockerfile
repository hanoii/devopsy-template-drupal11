# syntax=docker/dockerfile:1

# Drupal on FrankenPHP (Caddy + PHP 8.4), built on the server by
# `devopsy @<target> --release` (build mode, devopsy's default). Code is owned by root and
# read-only; the only writable paths at runtime are /tmp and the storage and
# logs mounts (compose.yaml). No USER here: compose.yaml's user: runs the app
# as APP_UID from its first process, with no capabilities.
#
# The base is a floating tag on purpose: build mode builds with --pull, so
# every release builds on its current image, PHP's security fixes included.
#
# The build context is the project root (see compose.yaml), filtered by
# Dockerfile.dockerignore next to this file.
#
# For the layer cache: composer keeps its downloads in a BuildKit cache
# mount, so a changed lock file only downloads the changed packages, and the
# runtime image copies dependencies (vendor, core, contrib) in their own
# layers before the code, so a release that only changes custom code or
# config rebuilds (and, in image mode, pushes and pulls) the code layer only.

########################################
# Base: PHP runtime shared by build and app
########################################
FROM dunglas/frankenphp:1-php8.4-trixie AS base

RUN install-php-extensions apcu gd intl opcache pdo_mysql zip

# curl for the healthcheck, mariadb-client for drush sql:*. No recommends, and
# the apt lists are removed in the same layer to keep the image small.
RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    curl \
    mariadb-client \
  && rm -rf /var/lib/apt/lists/*

########################################
# Build: composer install
########################################
FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
  COMPOSER_CACHE_DIR=/composer-cache

WORKDIR /app

# Only what composer needs, so vendor/ and web/core stay cached until
# dependencies change.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/composer-cache,sharing=locked \
  composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
  && mkdir -p web/libraries web/modules/contrib web/themes/contrib

# Then the rest of the project (settings, config, custom code) on top.
COPY . .

# Public files live in the storage mount, next to private files.
RUN rm -rf web/sites/default/files \
  && ln -s /app/storage/public web/sites/default/files

########################################
# Runtime
########################################
FROM base AS app

# Not root, and not 1000: that is the devopsy deploy user on servers, in the
# docker group, so an escape would land as root-equivalent. The user only
# names the UID (and gives it /tmp as home): compose.yaml runs the container
# as ${APP_UID:-10001} and passes the same value here.
ARG APP_UID=10001

# The root filesystem is read-only at runtime: Caddy keeps its data and config
# in /tmp, a tmpfs (see compose.yaml).
ENV STORAGE_DIR=/app/storage \
  XDG_CONFIG_HOME=/tmp/caddy/config \
  XDG_DATA_HOME=/tmp/caddy/data

RUN useradd --uid ${APP_UID} --user-group --no-create-home --home-dir /tmp --shell /bin/bash app \
  && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# No privilege to gain: the app runs on 8080 without capabilities
# (compose.yaml drops them all), so the binary's file capability for low
# ports would only stop it from starting (execve refuses a file capability
# the bounding set lacks: "operation not permitted"); and no setuid or
# setgid binaries (su, passwd, mount...) for a shell in the container to use.
RUN setcap -r /usr/local/bin/frankenphp \
  && find / -xdev -perm /6000 -type f -exec chmod a-s {} +

# Dependencies, each in its own layer. BuildKit keys these copies on their
# content, so a layer whose files did not change keeps its digest even after
# composer install reran: a module update changes contrib modules, not core
# or vendor. Permissions are normalized while copying (no extra layer): the
# build context keeps the host's modes, and the app user must be able to
# read everything but write nothing.
#
# vendor/composer (installed.json, autoload maps) and vendor/autoload.php
# (its class name has the lock file's hash) change on every lock change, so
# they get their own small layer after the libraries.
COPY --from=build --chmod=u=rwX,go=rX --exclude=composer --exclude=autoload.php /app/vendor /app/vendor
COPY --from=build --chmod=u=rwX,go=rX /app/web/core /app/web/core
COPY --from=build --chmod=u=rwX,go=rX /app/web/libraries /app/web/libraries
COPY --from=build --chmod=u=rwX,go=rX /app/web/themes/contrib /app/web/themes/contrib
COPY --from=build --chmod=u=rwX,go=rX /app/web/modules/contrib /app/web/modules/contrib
COPY --from=build --chmod=u=rwX,go=rX /app/vendor/composer /app/vendor/composer
COPY --from=build --chmod=u=rwX,go=rX /app/vendor/autoload.php /app/vendor/autoload.php

# Runtime configuration and scripts.
COPY .devopsy/docker/Caddyfile /etc/frankenphp/Caddyfile
COPY .devopsy/docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY --chmod=755 .devopsy/docker/entrypoint /usr/local/bin/entrypoint
# drush with the site's root, whatever the working directory.
COPY --chmod=755 .devopsy/docker/drush-wrapper /usr/local/bin/drush

# The code: everything else, the layer most releases change.
COPY --from=build --chmod=u=rwX,go=rX \
  --exclude=vendor \
  --exclude=web/core \
  --exclude=web/modules/contrib \
  --exclude=web/themes/contrib \
  --exclude=web/libraries \
  /app /app

WORKDIR /app
EXPOSE 8080

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
