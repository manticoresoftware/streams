#!/usr/bin/env sh
set -eu

KAFKA_NAMESPACE=${KAFKA_NAMESPACE:-kafka}
KAFKA_RELEASE_NAME=${KAFKA_RELEASE_NAME:-my-kafka}
KAFKA_CHART_VERSION=${KAFKA_CHART_VERSION:-32.4.3}
TIMEOUT_SECONDS=${TIMEOUT_SECONDS:-600}

collect_diagnostics() {
  status=$?
  [ "$status" -eq 0 ] && return

  set +e
  echo "Kafka installation failed; collecting diagnostics" >&2
  kubectl -n "$KAFKA_NAMESPACE" get all,cm,pvc -o wide >&2
  kubectl -n "$KAFKA_NAMESPACE" get events --sort-by=.lastTimestamp >&2
  helm status "$KAFKA_RELEASE_NAME" -n "$KAFKA_NAMESPACE" >&2
  helm get values "$KAFKA_RELEASE_NAME" -n "$KAFKA_NAMESPACE" --all >&2
  for pod in $(kubectl -n "$KAFKA_NAMESPACE" get pods -o name 2>/dev/null); do
    kubectl -n "$KAFKA_NAMESPACE" describe "$pod" >&2
    kubectl -n "$KAFKA_NAMESPACE" logs "$pod" --all-containers --prefix >&2
    kubectl -n "$KAFKA_NAMESPACE" logs "$pod" --all-containers --prefix --previous >&2
  done
  exit "$status"
}

trap collect_diagnostics EXIT

helm repo add bitnami https://charts.bitnami.com/bitnami
helm repo update bitnami
helm upgrade --install "$KAFKA_RELEASE_NAME" bitnami/kafka \
  --namespace "$KAFKA_NAMESPACE" --create-namespace \
  --version "$KAFKA_CHART_VERSION" \
  --set global.security.allowInsecureImages=true \
  --set image.repository=bitnamilegacy/kafka \
  --set controller.replicaCount=1 \
  --set broker.replicaCount=0 \
  --set listeners.client.protocol=PLAINTEXT \
  --set listeners.controller.protocol=PLAINTEXT \
  --set listeners.interbroker.protocol=PLAINTEXT \
  --set provisioning.enabled=false \
  --wait --timeout "${TIMEOUT_SECONDS}s"

kubectl -n "$KAFKA_NAMESPACE" rollout status statefulset/"${KAFKA_RELEASE_NAME}"-controller --timeout="${TIMEOUT_SECONDS}s"
