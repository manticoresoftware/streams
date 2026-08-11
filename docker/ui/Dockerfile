FROM php:8.3-fpm

RUN apt update && apt install -y \
    librdkafka-dev \
    zlib1g-dev \
    libpng-dev \
    libjpeg-dev \
    git \
    libfreetype6-dev \
    libzip-dev \
    procps \
    && pecl install rdkafka \
    && echo "extension=rdkafka.so" > /usr/local/etc/php/conf.d/rdkafka.ini \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure pcntl --enable-pcntl \
    && docker-php-ext-install pdo_mysql mysqli -j$(nproc) gd zip pcntl sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
COPY --chown=www-data:www-data source /var/www/html/

RUN cp .env.example .env \
    && composer install --no-interaction \
    && composer dumpautoload -o \
    && php artisan key:generate \
    && chown -R www-data:www-data /var/www/html \
    && mkdir -p /var/www/.config \
    && chown -R www-data:www-data /var/www/.config \
    && chmod -R 755 /var/www/.config \
    && find storage bootstrap/cache -type d -exec chmod 775 {} + \
    && find storage bootstrap/cache -type f -exec chmod 664 {} + \
    && chmod 775 .env phpunit.xml

USER www-data
CMD ["php-fpm"]
