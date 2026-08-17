#!/usr/bin/env bash
set -euxo pipefail

kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --create --if-not-exists --topic my-docs --partitions=1 --replication-factor=1
kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --create --if-not-exists --topic my-results --partitions=1 --replication-factor=1
