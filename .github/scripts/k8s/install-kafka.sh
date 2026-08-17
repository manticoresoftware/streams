#!/usr/bin/env sh
set -eu

KAFKA_NAMESPACE=${KAFKA_NAMESPACE:-kafka}
KAFKA_RELEASE_NAME=${KAFKA_RELEASE_NAME:-my-kafka}
KAFKA_CHART_VERSION=${KAFKA_CHART_VERSION:-32.4.3}
KAFKA_CHART_SHA256=${KAFKA_CHART_SHA256:-235ae1c09c837fbb1e670ddb83c816352ee91c05a5336e31bbd51f243a7a3687}
TIMEOUT_SECONDS=${TIMEOUT_SECONDS:-600}

collect_diagnostics() {
  status=$?
  [ "$status" -eq 0 ] && return

  set +e
  echo "Kafka installation failed; collecting diagnostics" >&2
  kubectl -n "$KAFKA_NAMESPACE" get all,cm,pvc -o wide >&2
  kubectl -n "$KAFKA_NAMESPACE" get events --sort-by=.lastTimestamp >&2
  helm status "$KAFKA_RELEASE_NAME" -n "$KAFKA_NAMESPACE" >&2
  for pod in $(kubectl -n "$KAFKA_NAMESPACE" get pods -o name 2>/dev/null); do
    kubectl -n "$KAFKA_NAMESPACE" describe "$pod" >&2
    kubectl -n "$KAFKA_NAMESPACE" logs "$pod" --all-containers --prefix >&2
    kubectl -n "$KAFKA_NAMESPACE" logs "$pod" --all-containers --prefix --previous >&2
  done
  exit "$status"
}

trap collect_diagnostics EXIT

chart_archive=$(mktemp)
curl --fail --location --proto '=https' --tlsv1.2 --retry 3 \
  --output "$chart_archive" \
  "https://charts.bitnami.com/bitnami/kafka-${KAFKA_CHART_VERSION}.tgz"
printf '%s  %s\n' "$KAFKA_CHART_SHA256" "$chart_archive" | sha256sum --check --status
helm upgrade --install "$KAFKA_RELEASE_NAME" "$chart_archive" \
  --namespace "$KAFKA_NAMESPACE" --create-namespace \
  --version "$KAFKA_CHART_VERSION" \
  --set image.repository=bitnamilegacy/kafka \
  --set image.tag=4.0.0-debian-12-r10 \
  --set image.digest=sha256:aa0b2aee8c5610dd1d18d48b4f1df0dbe3267b5d4c338d36c9af9cbf0529c0b0 \
  --set controller.replicaCount=1 \
  --set broker.replicaCount=0 \
  --set listeners.client.protocol=PLAINTEXT \
  --set listeners.controller.protocol=PLAINTEXT \
  --set listeners.interbroker.protocol=PLAINTEXT \
  --set overrideConfiguration.offsets\\.topic\\.replication\\.factor=1 \
  --set overrideConfiguration.transaction\\.state\\.log\\.replication\\.factor=1 \
  --set overrideConfiguration.transaction\\.state\\.log\\.min\\.isr=1 \
  --set provisioning.enabled=false \
  --wait --timeout "${TIMEOUT_SECONDS}s"

kubectl -n "$KAFKA_NAMESPACE" rollout status statefulset/"${KAFKA_RELEASE_NAME}"-controller --timeout="${TIMEOUT_SECONDS}s"
kubectl -n "$KAFKA_NAMESPACE" exec "${KAFKA_RELEASE_NAME}"-controller-0 -c kafka -- \
  /opt/bitnami/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --create --if-not-exists \
  --topic ci-kafka-coordinator-readiness --partitions=1 --replication-factor=1
kubectl -n "$KAFKA_NAMESPACE" exec "${KAFKA_RELEASE_NAME}"-controller-0 -c kafka -- sh -c \
  "printf 'ready\\n' | /opt/bitnami/kafka/bin/kafka-console-producer.sh --bootstrap-server localhost:9092 --topic ci-kafka-coordinator-readiness"
timeout 60s kubectl -n "$KAFKA_NAMESPACE" exec "${KAFKA_RELEASE_NAME}"-controller-0 -c kafka -- \
  /opt/bitnami/kafka/bin/kafka-console-consumer.sh --bootstrap-server localhost:9092 \
  --topic ci-kafka-coordinator-readiness --group ci-kafka-coordinator-readiness --from-beginning \
  --max-messages 1 --timeout-ms 30000 >/dev/null
