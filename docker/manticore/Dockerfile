ARG MANTICORE_IMAGE=manticoresearch/manticore:dev-32.0.5
ARG COMPOSER_IMAGE=composer:2.11.2
FROM ${COMPOSER_IMAGE} AS composer-bin

FROM ${MANTICORE_IMAGE} AS runtime-base

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        ca-certificates curl supervisor logrotate \
    && curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb \
    && dpkg -i /tmp/debsuryorg-archive-keyring.deb && rm /tmp/debsuryorg-archive-keyring.deb \
    && . /etc/os-release \
    && echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" > /etc/apt/sources.list.d/php.list \
    && apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        php8.5-cli php8.5-mysql php8.5-zip php8.5-curl php8.5-xml php8.5-mbstring \
    && php -r 'if (PHP_VERSION_ID < 80513 || PHP_VERSION_ID >= 80600) exit(1);' \
    && rm -rf /var/lib/apt/lists/*

FROM runtime-base AS builder
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        gcc libc6-dev make git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer

WORKDIR /build/deps
COPY composer.json composer.lock ./
RUN composer validate --no-check-publish \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress \
    && composer check-platform-reqs --no-dev \
    && sed -i "s/'version' => 2.0,//" vendor/manticoresoftware/manticoresearch-auto-replication/src/K8s/ApiClient.php

WORKDIR /build/blended
COPY blended/ ./
RUN cp /usr/include/manticore/sphinxudf.h . && make

FROM runtime-base AS runtime

# Keep write access to the runtime directories, without copying up the
# read-only dictionaries and modules from the base image.
RUN mkdir -p /etc/manticoresearch/plugins /var/log/supervisor \
    && chown manticore /etc/manticoresearch /etc/manticoresearch/plugins /var/log/supervisor \
    && chown -R manticore /var/log/manticore /var/lib/manticore /var/run/manticore

COPY --chown=manticore:manticore composer.json replica.php alter.php quorum.php env_reader.php /etc/manticoresearch/
COPY --chown=manticore:manticore --chmod=0755 manticore.conf starter.sh l_probe.sh r_probe.sh quorum_fixer.sh /etc/manticoresearch/
COPY --chown=manticore:manticore lang_package/en.pak lang_package/de.pak lang_package/ru.pak /usr/share/manticore/
COPY supervisord.conf /etc/supervisord.conf
COPY logrotate/manticore /etc/logrotate.d/manticore
COPY --chmod=0755 logrotate/logrotate.sh /logrotate.sh
COPY --from=builder --chown=manticore:manticore /build/deps/vendor/ /etc/manticoresearch/vendor/
COPY --from=builder --chown=manticore:manticore /build/deps/composer.lock /etc/manticoresearch/composer.lock
COPY --from=builder --chown=manticore:manticore /build/blended/blended.so /etc/manticoresearch/plugins/blended.so

WORKDIR /etc/manticoresearch/
USER manticore:manticore
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]

# Streams-only pruning. The official Manticore image is unchanged.
# Retain package metadata and shared dependencies. The reference profile is
# the previous slim image; the default Streams profile prunes more features.
FROM runtime AS prune
USER root
ARG PRUNE_PROFILE=streams
COPY prune-runtime.sh /tmp/prune-runtime.sh
RUN sh /tmp/prune-runtime.sh "$PRUNE_PROFILE" && rm /tmp/prune-runtime.sh

# Flatten the cleaned filesystem. Merely deleting files in a descendant layer
# would leave their bytes in the original base layer.
FROM scratch AS slim-runtime
COPY --from=prune / /

# Restore the image configuration inherited by the full runtime.
ENV LANG=C.UTF-8 LC_ALL=C.UTF-8 \
    MANTICORE_CONFIG="/etc/manticoresearch/manticore.conf.sh|/etc/manticoresearch/manticore.conf"
VOLUME ["/var/lib/manticore"]
EXPOSE 9306 9308 9312
ENTRYPOINT ["docker-entrypoint.sh"]
WORKDIR /etc/manticoresearch/
USER manticore:manticore
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]
