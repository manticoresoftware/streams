FROM php:8.3-fpm@sha256:b7b2846437277f3d0a6f43161e4e91f82f3649c3e773d36509d53ca6519e2446

# Install Nginx, kcat, mysql-client, curl, supervisor, procps, and oniguruma
RUN apt-get update && apt-get install -y \
    nginx \
    kafkacat \
    mariadb-client \
    curl \
    supervisor \
    procps \
    libonig-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install and enable PHP extensions mysqli and mbstring
RUN docker-php-ext-install mysqli mbstring && \
    docker-php-ext-enable mysqli mbstring

# Create a non-root user with UID 999 and GID 999 to match k8s fsGroup
RUN groupadd -g 999 appuser && useradd -u 999 -g appuser -m appuser

# Create necessary directories with proper permissions for appuser
RUN mkdir -p /storage /var/log/nginx /var/lib/nginx /var/lib/nginx/body /var/lib/nginx/tmp /var/run/nginx /var/run && \
    chmod -R 755 /storage /var/log/nginx /var/lib/nginx /var/run/nginx /var/run && \
    touch /var/run/nginx.pid /var/run/crond.pid && \
    chown -R 999:999 /storage /var/log/nginx /var/lib/nginx /var/run/nginx /var/run /var/run/nginx.pid /var/run/crond.pid

# Copy application files
COPY index.php insert.php /var/www/html/
COPY get_messages.sh /get_messages.sh
COPY loop.sh /loop.sh
RUN chmod -R 755 /get_messages.sh /loop.sh && chown -R 999:999 /get_messages.sh /var/www/html/ /loop.sh

# Copy configuration files
COPY nginx.conf /etc/nginx/sites-available/default
COPY php-fpm.conf /etc/php/8.3/fpm/pool.d/www.conf
COPY supervisord.conf /etc/supervisor/supervisord.conf
RUN chmod 644 /etc/nginx/sites-available/default /etc/php/8.3/fpm/pool.d/www.conf /etc/supervisor/supervisord.conf \
    && chown 999:999 /etc/nginx/sites-available/default /etc/php/8.3/fpm/pool.d/www.conf /etc/supervisor/supervisord.conf

# Create symlink to Nginx site configuration, overwriting if it exists
RUN ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/

# Expose port 8080
EXPOSE 8080

# Label for non-root compatibility
LABEL org.opencontainers.image.user=999

# Run supervisord as non-root user
USER 999
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]