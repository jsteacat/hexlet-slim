# Runtime image for the Slim app deployed on Render (Docker runtime).
# Render sets the PORT env var (default 10000) and requires the service
# to bind 0.0.0.0. See https://render.com/docs/web-services#port-binding
FROM php:8.5-cli

# The official php image ships without unzip/git; Composer needs unzip
# to unpack package zip archives (see https://getcomposer.org/doc/00-intro.md)
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/*

# mbstring, json, simplexml are already built into the official php image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PORT=10000 \
    PHP_CLI_SERVER_WORKERS=4

WORKDIR /app

# Dependencies first: this layer stays cached while composer.* are unchanged
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

COPY . .

# The app writes users.json and requests.log into data/ at runtime
RUN chown -R www-data:www-data /app/data

USER www-data
EXPOSE 10000

# Shell form on purpose: $PORT must be expanded when the container starts
CMD php -S 0.0.0.0:$PORT -t public public/index.php
