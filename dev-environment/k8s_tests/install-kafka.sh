#!/usr/bin/env sh
set -eu

KAFKA_NAMESPACE=${KAFKA_NAMESPACE:-kafka}
KAFKA_RELEASE_NAME=${KAFKA_RELEASE_NAME:-my-kafka}
KAFKA_CHART_VERSION=${KAFKA_CHART_VERSION:-32.0.1}
TIMEOUT_SECONDS=${TIMEOUT_SECONDS:-600}

helm repo add bitnami https://charts.bitnami.com/bitnami
helm repo update bitnami
helm upgrade --install "$KAFKA_RELEASE_NAME" bitnami/kafka \
  --namespace "$KAFKA_NAMESPACE" --create-namespace \
  --version "$KAFKA_CHART_VERSION" \
  --set controller.replicaCount=1 \
  --set broker.replicaCount=0 \
  --set listeners.client.protocol=PLAINTEXT \
  --set listeners.controller.protocol=PLAINTEXT \
  --set listeners.interbroker.protocol=PLAINTEXT \
  --set provisioning.enabled=false \
  --wait --timeout "${TIMEOUT_SECONDS}s"

kubectl -n "$KAFKA_NAMESPACE" rollout status statefulset/"${KAFKA_RELEASE_NAME}"-controller --timeout="${TIMEOUT_SECONDS}s"
