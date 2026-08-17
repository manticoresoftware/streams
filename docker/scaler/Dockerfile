FROM php:8.4.3-fpm-alpine3.20@sha256:5f6c116c7f4ac2cd8e23c2f94bcff487351e0591d1813e4c6d38bff2c0563c5a

# Copy from docker/scaler/ context
COPY index.php curl.php scaler.php response.php init.sh /scaler_source_code/

RUN apk add curl-dev

RUN docker-php-ext-configure curl && \
    docker-php-ext-install curl mysqli && \
    docker-php-ext-enable curl mysqli