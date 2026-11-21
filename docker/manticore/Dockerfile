FROM manticoresearch/manticore:dev-32.0.5

RUN apt-get update && echo 'debconf debconf/frontend select Noninteractive' | debconf-set-selections \
    && apt-get -y install wget supervisor logrotate git zip unzip gnupg curl tree cmake \
    && curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb \
    && dpkg -i /tmp/debsuryorg-archive-keyring.deb && rm /tmp/debsuryorg-archive-keyring.deb \
    && . /etc/os-release \
    && echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" > /etc/apt/sources.list.d/php.list \
    && apt-get update && apt-get install -y \
    php8.5-cli php8.5-mysql php8.5-zip php8.5-curl php8.5-xml php8.5-mbstring \
    && php -r 'if (PHP_VERSION_ID < 80512 || PHP_VERSION_ID >= 80600) exit(1);' \
    && rm -rf /var/lib/apt/lists/*

# Copy from docker/manticore/ context
COPY composer.json manticore.conf starter.sh replica.php alter.php l_probe.sh r_probe.sh quorum.php env_reader.php quorum_fixer.sh /etc/manticoresearch/
COPY lang_package/en.pak lang_package/de.pak lang_package/ru.pak /usr/share/manticore/
COPY supervisord.conf /etc/supervisord.conf
COPY blended/* /tmp/blended/
COPY logrotate/manticore /etc/logrotate.d/manticore
COPY logrotate/logrotate.sh /logrotate.sh
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN cd /etc/manticoresearch/ && \
    composer update && \
    mkdir /etc/manticoresearch/plugins/ && cp /usr/include/manticore/sphinxudf.h /tmp/blended/ && \
    cd /tmp/blended && make && mv /tmp/blended/blended.so /etc/manticoresearch/plugins/ && \
    mkdir -p /var/log/supervisor && chown -R manticore /var/log/manticore/ /var/lib/manticore \
    /var/run/manticore /etc/manticoresearch/ /usr/share/manticore/ /var/log/supervisor && \
    chmod +x /etc/manticoresearch/l_probe.sh /etc/manticoresearch/r_probe.sh && \
    sed -i 's/'"'"'version'"'"' => 2.0\,//' /etc/manticoresearch/vendor/manticoresoftware/manticoresearch-auto-replication/src/K8s/ApiClient.php

WORKDIR /etc/manticoresearch/

USER manticore:manticore

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]