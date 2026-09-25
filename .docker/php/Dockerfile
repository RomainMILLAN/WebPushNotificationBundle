FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libgmp-dev libzip-dev libpq-dev \
    && docker-php-ext-install gmp bcmath pdo_mysql pdo_pgsql zip \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN echo 'memory_limit=1G' > /usr/local/etc/php/conf.d/memory.ini

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer/cache

WORKDIR /app
