FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql sockets \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
