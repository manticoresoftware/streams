#!/usr/bin/env bash
set -euxo pipefail

input_start_offset=$(kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-get-offsets.sh --bootstrap-server localhost:9092 --topic my-docs | awk -F: 'END { print $3 }')
start_offset=$(kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-get-offsets.sh --bootstrap-server localhost:9092 --topic my-results | awk -F: 'END { print $3 }')
echo "INPUT_START_OFFSET=${input_start_offset:-0}" >> "$GITHUB_ENV"
echo "START_OFFSET=${start_offset:-0}" >> "$GITHUB_ENV"
