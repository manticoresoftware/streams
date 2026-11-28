#!/usr/bin/env bash
set -euo pipefail

end=$(( $(date +%s) + TIMEOUT_SECONDS ))
expected_offset=$((START_OFFSET + EXPECTED_RECORDS))
expected_input_offset=$((INPUT_START_OFFSET + FIXTURE_RECORDS))

# Frozen Java reference: 43,403 fixture inputs, 9,418 outputs, OUTPUT_DOCS=0010.
# Java and Go matched in https://github.com/manticoresoftware/streams/actions/runs/46117851271
# Independently reproduced in the regular E2E run 46117912758.
expected_sha256=fcc241b7c766e00a2e6a7b036a7c3ab7f12bc5a9859c6d3cb10b9c57457b9142

kafka() {
  local tool=$1
  shift
  kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- \
    "/opt/bitnami/kafka/bin/$tool" --bootstrap-server localhost:9092 "$@"
}

output_offset() {
  kafka kafka-get-offsets.sh --topic my-results | awk -F: '
    $1 == "my-results" && $2 == 0 && $3 ~ /^[0-9]+$/ { offset = $3; found++ }
    END { if (found != 1 || NR != 1) exit 1; print offset }
  '
}

next_report=0
pipeline_name=${PIPELINE#"${RELEASE_NAME}"-}
while [ "$(date +%s)" -lt "$end" ]; do
  current_offset=$(output_offset)
  if [ "$current_offset" -gt "$expected_offset" ]; then
    echo "Kafka output exceeded expected offset $expected_offset: $current_offset" >&2
    exit 1
  fi
  if [ "$current_offset" -eq "$expected_offset" ]; then
    # Do not accept a transient correct count while the worker still has
    # unprocessed input and may produce additional records.
    committed_offset=$(kafka kafka-consumer-groups.sh --describe --group ms_test_stream |
      awk '$2 == "my-docs" && $3 == 0 { print $4 }')
    if [ "$committed_offset" = "$expected_input_offset" ]; then
      output_file=$(mktemp)
      trap 'rm -f "$output_file"' EXIT
      timeout 150s kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- \
        /opt/bitnami/kafka/bin/kafka-console-consumer.sh --bootstrap-server localhost:9092 \
        --topic my-results --partition 0 --offset "$START_OFFSET" \
        --max-messages "$EXPECTED_RECORDS" --timeout-ms 90000 > "$output_file"
      actual_sha256=$(python3 .github/scripts/k8s/kafka-output-sha256.py "$output_file" "$EXPECTED_RECORDS")
      [ "$(output_offset)" -eq "$expected_offset" ] || {
        echo "Kafka output changed while collecting the fixture" >&2
        exit 1
      }
      printf 'Processed exactly %s records; canonical output SHA-256: %s\n' "$EXPECTED_RECORDS" "$actual_sha256"
      if [ "$actual_sha256" != "$expected_sha256" ]; then
        printf 'Kafka output differs from the Java golden reference:\n  expected: %s\n  actual:   %s\n' \
          "$expected_sha256" "$actual_sha256" >&2
        exit 1
      fi
      echo 'Kafka output matches the Java golden reference, including duplicate counts.'
      exit 0
    fi
  fi
  now=$(date +%s)
  if [ "$now" -ge "$next_report" ]; then
    printf 'Kafka output: start=%s current=%s expected=%s\n' "$START_OFFSET" "$current_offset" "$expected_offset"
    kubectl -n "$APP_NAMESPACE" get statefulset "$PIPELINE"
    kubectl -n "$APP_NAMESPACE" get pods -l "name=${pipeline_name}" -o wide
    for pod in $(kubectl -n "$APP_NAMESPACE" get pods -l "name=${pipeline_name}" -o name); do
      for container in $(kubectl -n "$APP_NAMESPACE" get "$pod" -o jsonpath='{.spec.containers[*].name}'); do
        case "$container" in
          *-worker)
            echo "--- Pipeline worker logs: $pod ($container), last 100 lines ---"
            kubectl -n "$APP_NAMESPACE" logs "$pod" -c "$container" --tail=100 --prefix || true
            ;;
        esac
      done
    done
    next_report=$((now + 30))
  fi
  sleep 10
done
echo "Pipeline $PIPELINE did not produce offset $expected_offset within ${TIMEOUT_SECONDS}s" >&2
echo '--- Kafka topic offsets ---'
kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-get-offsets.sh --bootstrap-server localhost:9092 --topic my-docs || true
kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-get-offsets.sh --bootstrap-server localhost:9092 --topic my-results || true
echo '--- Kafka consumer group ms_test_stream ---'
kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-consumer-groups.sh --bootstrap-server localhost:9092 --describe --group ms_test_stream || true
kubectl -n "$APP_NAMESPACE" describe "statefulset/$PIPELINE" || true
kubectl -n "$APP_NAMESPACE" get events --sort-by=.lastTimestamp | tail -30 || true
kubectl -n "$APP_NAMESPACE" get pods -l "name=${pipeline_name}" -o wide || true
for pod in $(kubectl -n "$APP_NAMESPACE" get pods -l "name=${pipeline_name}" -o name); do
  kubectl -n "$APP_NAMESPACE" describe "$pod" || true
  for container in $(kubectl -n "$APP_NAMESPACE" get "$pod" -o jsonpath='{.spec.containers[*].name}'); do
    case "$container" in
      *-manticore|*-worker)
        echo "--- Pipeline container logs: $pod ($container) ---"
        kubectl -n "$APP_NAMESPACE" logs "$pod" -c "$container" --prefix || true
        echo "--- Previous pipeline container logs: $pod ($container) ---"
        kubectl -n "$APP_NAMESPACE" logs "$pod" -c "$container" --previous --prefix || true
        ;;
    esac
  done
done
exit 1
