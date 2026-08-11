FROM docker.io/bitnami/kafka:3.4

COPY inflate.sh /
COPY create-topic.sh /
COPY test_data.tar.gz /

USER 0
RUN tar -xzvf /test_data.tar.gz && chmod 0777 test_data.json
RUN chmod 0777 /inflate.sh
RUN chmod 0777 /create-topic.sh
USER 1001
