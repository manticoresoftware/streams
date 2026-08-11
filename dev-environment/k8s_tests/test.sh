#!/usr/bin/env sh
set -eu

APP_NAMESPACE=${APP_NAMESPACE:-manticore-streams}
KAFKA_NAMESPACE=${KAFKA_NAMESPACE:-kafka}
RELEASE_NAME=${RELEASE_NAME:-manticore-streams}
KAFKA_RELEASE_NAME=${KAFKA_RELEASE_NAME:-my-kafka}
TIMEOUT_SECONDS=${TIMEOUT_SECONDS:-600}
EXPECTED_RECORDS=${EXPECTED_RECORDS:-9418}
KAFKA_POD=${KAFKA_POD:-${KAFKA_RELEASE_NAME}-controller-0}
UI_DEPLOYMENT=${UI_DEPLOYMENT:-${RELEASE_NAME}-manticoresearch-ui}
UI_CONTAINER=${UI_CONTAINER:-manticoresearch-ui}

fail() { printf '%s\n' "ERROR: $*" >&2; exit 1; }
run_kafka() { kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -- "$@"; }
offset() { run_kafka sh -c "/opt/bitnami/kafka/bin/kafka-run-class.sh kafka.tools.GetOffsetShell --broker-list localhost:9092 --topic my-results" | awk -F: 'END { print $3 }'; }

kubectl get namespace "$APP_NAMESPACE" >/dev/null || fail "application namespace $APP_NAMESPACE does not exist"
kubectl -n "$KAFKA_NAMESPACE" get pod "$KAFKA_POD" >/dev/null || fail "Kafka pod $KAFKA_POD does not exist"
kubectl -n "$APP_NAMESPACE" rollout status deployment/"$UI_DEPLOYMENT" --timeout="${TIMEOUT_SECONDS}s"
kubectl -n "$APP_NAMESPACE" rollout status deployment/"${RELEASE_NAME}-manticoresearch-scaler" --timeout="${TIMEOUT_SECONDS}s"
kubectl -n "$APP_NAMESPACE" rollout status statefulset/"${RELEASE_NAME}-manticoresearch-columnar" --timeout="${TIMEOUT_SECONDS}s"
kubectl -n "$APP_NAMESPACE" rollout status statefulset/"${RELEASE_NAME}-manticoresearch-ui-mysql" --timeout="${TIMEOUT_SECONDS}s"

UI_POD=$(kubectl -n "$APP_NAMESPACE" get pods -l name=manticoresearch-ui -o jsonpath='{.items[0].metadata.name}')
[ -n "$UI_POD" ] || fail "UI pod was not found"

run_kafka /opt/bitnami/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --create --if-not-exists --topic my-docs --partitions=1 --replication-factor=1
run_kafka /opt/bitnami/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --create --if-not-exists --topic my-results --partitions=1 --replication-factor=1

kubectl -n "$APP_NAMESPACE" exec "$UI_POD" -c "$UI_CONTAINER" -- sh -c 'sed -i "s/production/cluster_testing/g" .env && sed -i "s/testing/cluster_testing/g" phpunit.xml'
kubectl -n "$APP_NAMESPACE" exec "$UI_POD" -c "$UI_CONTAINER" -- php artisan migrate:fresh --seed
kubectl -n "$APP_NAMESPACE" exec "$UI_POD" -c "$UI_CONTAINER" -- sh -c 'php -d short_open_tag=off ./vendor/phpunit/phpunit/phpunit --do-not-cache-result --testsuite Cluster --stderr'

kubectl cp dev-environment/kafka/test_data.tar.gz "$KAFKA_NAMESPACE/$KAFKA_POD:/tmp/test_data.tar.gz"
run_kafka tar -xzf /tmp/test_data.tar.gz -C /tmp
START_OFFSET=$(offset)
START_OFFSET=${START_OFFSET:-0}
run_kafka sh -c '/opt/bitnami/kafka/bin/kafka-console-producer.sh --broker-list localhost:9092 --topic my-docs < /tmp/test_data.json'

end=$(( $(date +%s) + TIMEOUT_SECONDS ))
while [ "$(date +%s)" -lt "$end" ]; do
  CURRENT_OFFSET=$(offset)
  CURRENT_OFFSET=${CURRENT_OFFSET:-0}
  if [ "$CURRENT_OFFSET" -eq $((START_OFFSET + EXPECTED_RECORDS)) ]; then
    printf 'Processed exactly %s records\n' "$EXPECTED_RECORDS"
    exit 0
  fi
  printf 'Waiting for output records: start=%s current=%s expected=%s\n' "$START_OFFSET" "$CURRENT_OFFSET" "$EXPECTED_RECORDS"
  sleep 10
done
fail "output offset did not reach $((START_OFFSET + EXPECTED_RECORDS)) within ${TIMEOUT_SECONDS}s"
