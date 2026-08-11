#!/usr/bin/env sh
set -u

ARTIFACT_DIR=${1:-artifacts/k8s}
APP_NAMESPACE=${APP_NAMESPACE:-manticore-streams}
KAFKA_NAMESPACE=${KAFKA_NAMESPACE:-kafka}
RELEASE_NAME=${RELEASE_NAME:-manticore-streams}
mkdir -p "$ARTIFACT_DIR"

for namespace in "$APP_NAMESPACE" "$KAFKA_NAMESPACE"; do
  kubectl get all,cm,pvc -n "$namespace" -o wide >"$ARTIFACT_DIR/${namespace}-resources.txt" 2>&1 || true
  kubectl get events -n "$namespace" --sort-by=.lastTimestamp >"$ARTIFACT_DIR/${namespace}-events.txt" 2>&1 || true
  for pod in $(kubectl get pods -n "$namespace" -o name 2>/dev/null || true); do
    safe_name=$(printf '%s' "$pod" | tr '/' '_')
    kubectl describe -n "$namespace" "$pod" >"$ARTIFACT_DIR/${namespace}-${safe_name}-describe.txt" 2>&1 || true
    kubectl logs -n "$namespace" "$pod" --all-containers --prefix >"$ARTIFACT_DIR/${namespace}-${safe_name}.log" 2>&1 || true
  done
done
helm status "$RELEASE_NAME" -n "$APP_NAMESPACE" >"$ARTIFACT_DIR/helm-status.txt" 2>&1 || true
helm get values "$RELEASE_NAME" -n "$APP_NAMESPACE" --all >"$ARTIFACT_DIR/helm-values.yaml" 2>&1 || true
kubectl get nodes -o wide >"$ARTIFACT_DIR/nodes.txt" 2>&1 || true
