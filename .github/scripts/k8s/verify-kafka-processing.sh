#!/usr/bin/env bash
set -euo pipefail

end=$(( $(date +%s) + TIMEOUT_SECONDS ))
expected_offset=$((START_OFFSET + EXPECTED_RECORDS))
next_report=0
pipeline_name=${PIPELINE#"${RELEASE_NAME}"-}
while [ "$(date +%s)" -lt "$end" ]; do
  current_offset=$(kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-get-offsets.sh --bootstrap-server localhost:9092 --topic my-results | awk -F: 'END { print $3 }')
  current_offset=${current_offset:-0}
  if [ "$current_offset" -eq "$expected_offset" ]; then
    printf 'Processed exactly %s records\n' "$EXPECTED_RECORDS"
    exit 0
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
