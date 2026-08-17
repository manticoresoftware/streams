#!/usr/bin/env bash
set -euo pipefail

started=$(date +%s)
end=$(( $(date +%s) + PIPELINE_CREATION_TIMEOUT_SECONDS ))
next_report=$started
pipeline=''
while [ "$(date +%s)" -lt "$end" ]; do
  pipeline=$(kubectl -n "$APP_NAMESPACE" get statefulsets -l app.kubernetes.io/component=worker -o jsonpath='{range .items[*]}{.metadata.name}{end}')
  [ -n "$pipeline" ] && break
  now=$(date +%s)
  if [ "$now" -ge "$next_report" ]; then
    printf 'Pipeline creation: waiting (%ss elapsed, %ss remaining)\n' "$((now - started))" "$((end - now))"
    kubectl -n "$APP_NAMESPACE" get statefulsets -l app.kubernetes.io/component=worker
    next_report=$((now + 30))
  fi
  sleep 5
done
if [ -z "$pipeline" ]; then
  echo "Pipeline creation: no StatefulSet after ${PIPELINE_CREATION_TIMEOUT_SECONDS}s" >&2
  echo '--- Pipeline failure summary ---' >&2
  kubectl -n "$APP_NAMESPACE" get pods,statefulsets,jobs -o wide || true
  kubectl -n "$APP_NAMESPACE" get events --sort-by=.lastTimestamp | tail -20 || true
  echo '--- UI service account permissions (statefulsets/services/PVCs) ---' >&2
  kubectl auth can-i create statefulsets.apps --as="system:serviceaccount:${APP_NAMESPACE}:ui-admin-sa-${APP_NAMESPACE}" -n "$APP_NAMESPACE" || true
  kubectl auth can-i create services --as="system:serviceaccount:${APP_NAMESPACE}:ui-admin-sa-${APP_NAMESPACE}" -n "$APP_NAMESPACE" || true
  kubectl auth can-i create persistentvolumeclaims --as="system:serviceaccount:${APP_NAMESPACE}:ui-admin-sa-${APP_NAMESPACE}" -n "$APP_NAMESPACE" || true
  echo '--- UI PHP logs (last 100 lines) ---' >&2
  kubectl -n "$APP_NAMESPACE" logs "$UI_POD" -c "$UI_CONTAINER" --tail=100 --prefix || true
  echo 'Detailed pod logs and full resources are in the k8s-e2e-diagnostics artifact.' >&2
  exit 1
fi
printf 'Pipeline creation: found %s after %ss\n' "$pipeline" "$(( $(date +%s) - started ))"
kubectl -n "$APP_NAMESPACE" rollout status "statefulset/$pipeline" --timeout="${TIMEOUT_SECONDS}s"
kubectl -n "$APP_NAMESPACE" get statefulset "$pipeline"
pipeline_name=${pipeline#"${RELEASE_NAME}"-}
kubectl -n "$APP_NAMESPACE" get pods -l "name=${pipeline_name}" -o wide
echo "PIPELINE=$pipeline" >> "$GITHUB_ENV"
