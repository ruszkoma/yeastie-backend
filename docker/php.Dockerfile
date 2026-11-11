# Mirrors the production host: PHP 8.5 with intl, mysqli, pdo_mysql, curl, openssl, mbstring, bcmath; no sodium.
FROM php:8.5-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev unzip git \
 && docker-php-ext-install intl mysqli pdo_mysql bcmath \
 && rm -f /usr/local/etc/php/conf.d/docker-php-ext-sodium.ini \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
