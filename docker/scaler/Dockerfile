FROM php:8.5.12-fpm-alpine3.23@sha256:24191bd33240dc934246144bc4d72cb612c446c4ce498472d01a3bdbaabd0ffa

# Copy from docker/scaler/ context
COPY index.php curl.php scaler.php response.php init.sh /scaler_source_code/

RUN apk add curl-dev

RUN docker-php-ext-configure curl && \
    docker-php-ext-install curl mysqli && \
    docker-php-ext-enable curl mysqli