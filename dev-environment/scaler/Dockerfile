FROM php:8-cli-alpine3.20

RUN apk add curl-dev

RUN docker-php-ext-configure curl && \
    docker-php-ext-install curl mysqli && \
    docker-php-ext-enable curl mysqli