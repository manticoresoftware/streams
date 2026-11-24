FROM php:8.5.13-cli-alpine3.23@sha256:422f6b0307a9742e531c828c033f524b612548d2d9e8e67551a88b21609d0a18

RUN apk add curl-dev

RUN docker-php-ext-configure curl && \
    docker-php-ext-install curl mysqli && \
    docker-php-ext-enable curl mysqli