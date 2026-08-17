#!/usr/bin/env bash
set -euo pipefail

kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_FIXTURE_PRODUCER_POD" -- sh -c 'timeout 120s /opt/bitnami/kafka/bin/kafka-console-producer.sh --bootstrap-server my-kafka.kafka.svc.cluster.local:9092 --topic my-docs < /tmp/test_data.json'
input_offset=$(kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-get-offsets.sh --bootstrap-server localhost:9092 --topic my-docs | awk -F: 'END { print $3 }')
expected_input_offset=$((INPUT_START_OFFSET + FIXTURE_RECORDS))
printf 'Kafka input: start=%s current=%s expected=%s\n' "$INPUT_START_OFFSET" "${input_offset:-0}" "$expected_input_offset"
[ "${input_offset:-0}" -eq "$expected_input_offset" ]
