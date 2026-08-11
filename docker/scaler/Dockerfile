FROM php:8.4.3-fpm-alpine3.20

# Copy from docker/scaler/ context
COPY index.php curl.php scaler.php response.php init.sh /scaler_source_code/

RUN apk add curl-dev

RUN docker-php-ext-configure curl && \
    docker-php-ext-install curl mysqli && \
    docker-php-ext-enable curl mysqli