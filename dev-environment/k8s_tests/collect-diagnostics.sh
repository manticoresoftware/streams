#!/usr/bin/env sh
set -u

ARTIFACT_DIR=${1:-artifacts/k8s}
APP_NAMESPACE=${APP_NAMESPACE:-manticore-streams}
KAFKA_NAMESPACE=${KAFKA_NAMESPACE:-kafka}
RELEASE_NAME=${RELEASE_NAME:-manticore-streams}
KUBECTL_TIMEOUT=${KUBECTL_TIMEOUT:-30s}
mkdir -p "$ARTIFACT_DIR"

for namespace in "$APP_NAMESPACE" "$KAFKA_NAMESPACE"; do
  kubectl --request-timeout="$KUBECTL_TIMEOUT" get all,cm,pvc -n "$namespace" -o wide >"$ARTIFACT_DIR/${namespace}-resources.txt" 2>&1 || true
  kubectl --request-timeout="$KUBECTL_TIMEOUT" get events -n "$namespace" --sort-by=.lastTimestamp >"$ARTIFACT_DIR/${namespace}-events.txt" 2>&1 || true
  for pod in $(kubectl --request-timeout="$KUBECTL_TIMEOUT" get pods -n "$namespace" -o name 2>/dev/null || true); do
    safe_name=$(printf '%s' "$pod" | tr '/' '_')
    (
      kubectl --request-timeout="$KUBECTL_TIMEOUT" describe -n "$namespace" "$pod" >"$ARTIFACT_DIR/${namespace}-${safe_name}-describe.txt" 2>&1 || true
      timeout 45s kubectl --request-timeout="$KUBECTL_TIMEOUT" logs -n "$namespace" "$pod" --all-containers --prefix >"$ARTIFACT_DIR/${namespace}-${safe_name}.log" 2>&1 || true
      timeout 45s kubectl --request-timeout="$KUBECTL_TIMEOUT" logs -n "$namespace" "$pod" --all-containers --previous --prefix >"$ARTIFACT_DIR/${namespace}-${safe_name}-previous.log" 2>&1 || true
    ) &
  done
  wait
done
timeout 45s helm status "$RELEASE_NAME" -n "$APP_NAMESPACE" >"$ARTIFACT_DIR/helm-status.txt" 2>&1 || true
timeout 45s helm get values "$RELEASE_NAME" -n "$APP_NAMESPACE" --all >"$ARTIFACT_DIR/helm-values.yaml" 2>&1 || true
kubectl --request-timeout="$KUBECTL_TIMEOUT" get nodes -o wide >"$ARTIFACT_DIR/nodes.txt" 2>&1 || true
