FROM php:8.5.12-cli-alpine3.23@sha256:dfe6deaf6ddec9d2678fa54e260eae6e951978be62d6f842a9ba6f71b5741d67

RUN apk add curl-dev

RUN docker-php-ext-configure curl && \
    docker-php-ext-install curl mysqli && \
    docker-php-ext-enable curl mysqli