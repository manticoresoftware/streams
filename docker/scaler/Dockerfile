FROM php:8.5.13-fpm-alpine3.23@sha256:b86f5233390db7a62d5333ccb8c0830625329b021e5e77a3e65f6a70141a4fcc

# Copy from docker/scaler/ context
COPY index.php curl.php scaler.php response.php init.sh /scaler_source_code/

RUN apk add curl-dev

RUN docker-php-ext-configure curl && \
    docker-php-ext-install curl mysqli && \
    docker-php-ext-enable curl mysqli

# The worker parses the response body as JSON: PHP notices must go to the log
# (php-fpm stderr), never into the response.
RUN printf 'display_errors = Off\nlog_errors = On\n' > "$PHP_INI_DIR/conf.d/zz-scaler.ini"