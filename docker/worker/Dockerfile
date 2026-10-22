FROM eclipse-temurin:21-jre-noble@sha256:ca397720325ceefe39ce397f186759fc87d9efafb2dc4ce53315980844c2f4f2

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        lsb-release \
        wget \
        default-mysql-client \
        software-properties-common \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        php8.5-cli \
        php8.5-mbstring \
    && php -r 'if (PHP_VERSION_ID < 80512 || PHP_VERSION_ID >= 80600) exit(1);' \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd -r worker && useradd -r -g worker worker

RUN mkdir -m 0777 /usr/src/myapp
RUN mkdir -m 0777 /usr/src/myapp/logs

# Copy from docker/worker/ context, with KafkaHandler.jar added by build_worker
COPY KafkaHandler.jar check.sh entrypoint.sh /usr/src/myapp/

RUN chown -R worker:worker /usr/src/myapp
WORKDIR /usr/src/myapp

USER worker
ENTRYPOINT ["/usr/src/myapp/entrypoint.sh"]
