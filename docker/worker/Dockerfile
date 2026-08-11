FROM eclipse-temurin:21-jre-noble

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        lsb-release \
        wget \
        default-mysql-client \
        php8.3-cli \
        php8.3-mbstring \
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
