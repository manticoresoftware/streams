FROM manticoresearch/manticore:7.4.6@sha256:9fb96a2bfeec4b81b84aad7d55db396c6389e135cc3be32d856c3f91e01a56e9

RUN apt-get update && echo 'debconf debconf/frontend select Noninteractive' | debconf-set-selections \
    && apt-get -y install wget software-properties-common supervisor logrotate git zip unzip wget gnupg curl tree cmake \
    && add-apt-repository -y ppa:ondrej/php && apt update && apt install -y \
    php-cli php-mysql php-zip php-curl php-xml php-mbstring && rm -rf /var/lib/apt/lists/*

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