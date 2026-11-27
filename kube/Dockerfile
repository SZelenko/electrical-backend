# syntax=docker/dockerfile:1
# Build context is the backend/ root:  docker build -f kube/Dockerfile --target dev .

# ---------- base: PHP-FPM 8.4 + extensions, composer, non-root user ----------
FROM php:8.4-fpm AS base

ARG APP_USER=app
ARG APP_UID=1000
ARG APP_GID=1000
ARG INSTALL_XDEBUG=0

RUN apt-get update && apt-get install -y \
    git unzip curl libzip-dev libicu-dev libpq-dev bash \
    && docker-php-ext-install intl pdo_pgsql zip opcache \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/*

RUN if [ "$INSTALL_XDEBUG" = "1" ]; then \
    pecl install xdebug && docker-php-ext-enable xdebug; \
fi

COPY kube/php/php.ini /usr/local/etc/php/conf.d/10-common.ini
COPY kube/php/php-fpm.ini /usr/local/etc/php/conf.d/20-fpm.ini

RUN mkdir -p /usr/local/etc/php/cli-conf.d
COPY kube/php/php-cli.ini /usr/local/etc/php/cli-conf.d/20-cli.ini

RUN mv /usr/local/bin/php /usr/local/bin/php-bin \
 && printf '#!/bin/sh\nPHP_INI_SCAN_DIR="/usr/local/etc/php/conf.d:/usr/local/etc/php/cli-conf.d" exec /usr/local/bin/php-bin "$@"\n' \
    > /usr/local/bin/php \
 && chmod +x /usr/local/bin/php

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# User with the host UID/GID so hostPath mounts keep correct ownership
RUN groupadd -g ${APP_GID} ${APP_USER} \
 && useradd -u ${APP_UID} -g ${APP_GID} -m -s /bin/bash ${APP_USER}

COPY kube/home/bash_aliases /tmp/bash_aliases
RUN cat /tmp/bash_aliases >> /home/${APP_USER}/.bashrc \
    && rm /tmp/bash_aliases \
    && chown ${APP_USER}:${APP_GID} /home/${APP_USER}/.bashrc

RUN mkdir -p /var/www/app && chown ${APP_USER}:${APP_GID} /var/www/app
WORKDIR /var/www/app

USER ${APP_USER}

ENV SHELL=/bin/bash

# ---------- dev: code is mounted from the host (hostPath), deps installed via kubectl exec ----------
FROM base AS dev

# ---------- prod: code + vendor baked into the image, cache warmed up ----------
FROM base AS prod

ARG APP_USER=app
ARG APP_GID=1000

ENV APP_ENV=prod \
    COMPOSER_ALLOW_SUPERUSER=1

COPY kube/php/php-prod.ini /usr/local/etc/php/conf.d/30-prod.ini

COPY --chown=${APP_USER}:${APP_GID} . .

RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --classmap-authoritative \
    && bin/console cache:warmup \
    && composer clear-cache

# ---------- nginx: static files + FastCGI proxy to the php-fpm container ----------
FROM nginx:1.27-alpine AS nginx

ENV PHP_FPM_HOST=localhost

COPY kube/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY --from=prod /var/www/app/public /var/www/app/public

EXPOSE 80
