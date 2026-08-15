#!/usr/bin/env bash
set -euxo pipefail

kubectl get namespace "$APP_NAMESPACE"
kubectl -n "$KAFKA_NAMESPACE" get pod "$KAFKA_POD"
kubectl -n "$APP_NAMESPACE" rollout status deployment/"$UI_DEPLOYMENT" --timeout="${TIMEOUT_SECONDS}s"
kubectl -n "$APP_NAMESPACE" rollout status deployment/"${RELEASE_NAME}-manticoresearch-scaler" --timeout="${TIMEOUT_SECONDS}s"
kubectl -n "$APP_NAMESPACE" rollout status statefulset/"${RELEASE_NAME}-manticoresearch-columnar" --timeout="${TIMEOUT_SECONDS}s"
kubectl -n "$APP_NAMESPACE" rollout status statefulset/"${RELEASE_NAME}-manticoresearch-ui-mysql" --timeout="${TIMEOUT_SECONDS}s"
ui_pod=$(kubectl -n "$APP_NAMESPACE" get pods -l name=manticoresearch-ui -o jsonpath='{.items[0].metadata.name}')
test -n "$ui_pod"
echo "UI_POD=$ui_pod" >> "$GITHUB_ENV"
