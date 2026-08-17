#!/usr/bin/env bash
set -euo pipefail

end=$(( $(date +%s) + TIMEOUT_SECONDS ))
next_report=0
group_members=''
while [ "$(date +%s)" -lt "$end" ]; do
  group_members=$(kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-consumer-groups.sh --bootstrap-server localhost:9092 --describe --group ms_test_stream --members --verbose 2>&1 || true)
  if printf '%s\n' "$group_members" | grep -Eq 'my-docs(:0|\(0\))'; then
    printf 'Kafka consumer group ms_test_stream is assigned my-docs partition 0\n'
    exit 0
  fi
  now=$(date +%s)
  if [ "$now" -ge "$next_report" ]; then
    printf 'Kafka consumer assignment: waiting for ms_test_stream to receive my-docs partition 0\n%s\n' "$group_members"
    next_report=$((now + 30))
  fi
  sleep 5
done
printf 'Kafka consumer group ms_test_stream was not assigned my-docs partition 0 within %ss\n%s\n' "$TIMEOUT_SECONDS" "$group_members" >&2
exit 1
