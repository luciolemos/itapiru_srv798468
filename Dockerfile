FROM php:8.4-cli-alpine

RUN apk add --no-cache oniguruma sqlite-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS oniguruma-dev sqlite-dev \
    && docker-php-ext-install mbstring pdo_sqlite \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
