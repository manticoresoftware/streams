FROM php:8.5.13-fpm@sha256:52ba6f178d0a08f348578b03595661b7ff13defafacf4c823d045f2ecba8db8f

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
RUN apt update && apt install -y librdkafka-dev zlib1g-dev libpng-dev libjpeg-dev git \
    libfreetype6-dev libzip-dev procps \
    && pecl install rdkafka-6.0.5  \
    && pecl install xdebug \
    && echo "extension=rdkafka.so" > /usr/local/etc/php/conf.d/rdkafka.ini \
    && echo "zend_extension=xdebug" > /usr/local/etc/php/conf.d/xdebug.ini \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure pcntl --enable-pcntl \
    && docker-php-ext-install pdo_mysql mysqli -j$(nproc) gd zip pcntl sockets \
    && rm -rf dev-environment docker docs helm-chart registry sources

USER www-data
WORKDIR /var/www/html/