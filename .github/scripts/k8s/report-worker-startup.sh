#!/usr/bin/env bash
set -euo pipefail

# The E2E pipeline has a single replica and a single assigned partition.
pod="${PIPELINE}-0"
pod_json=$(kubectl -n "$APP_NAMESPACE" get pod "$pod" -o json)
container=$(jq -er '.spec.containers[] | select(.name | endswith("-worker")) | .name' <<<"$pod_json")
worker_api="/api/v1/namespaces/$APP_NAMESPACE/pods/$pod:8081/proxy"
deadline=$((SECONDS + TIMEOUT_SECONDS))
ready=''
while true; do
  remaining=$((deadline - SECONDS))
  (( remaining > 0 )) || break
  if kubectl --request-timeout="${remaining}s" get --raw "$worker_api/ready" >/dev/null 2>&1; then
    remaining=$((deadline - SECONDS))
    (( remaining > 0 )) || break
    logs=$(kubectl --request-timeout="${remaining}s" -n "$APP_NAMESPACE" logs "$pod" -c "$container" --timestamps)
    ready=$(awk '/Worker ready to process after/ {print $1; exit}' <<<"$logs")
    [ -n "$ready" ] && break
  fi
  sleep 1
done
if [ -z "$ready" ]; then
  echo "Worker did not report readiness for Kafka and Manticore within ${TIMEOUT_SECONDS}s" >&2
  exit 1
fi
metrics=$(kubectl get --raw "$worker_api/metrics")
grep -q '^# HELP worker_batch_size ' <<<"$metrics"

# Verify the deployed pipeline's real environment compiles natively. This
# command launches a separate read-only audit process without Kafka or Java.
audit=$(kubectl -n "$APP_NAMESPACE" exec "$pod" -c "$container" -- /usr/src/myapp/streams-worker --audit-mapping)
jq -e '.schema_version == 1 and (.profiles | length) == 1 and .profiles[0].static_native == true and .profiles[0].runtime_parity_known == false' <<<"$audit" >/dev/null
echo 'Deployed worker mapping configuration: static native check passed (runtime parity not inferred)'

assigned=$(awk '/Kafka partitions assigned after/ {print $1; exit}' <<<"$logs")
test -n "$assigned" || { echo 'First Kafka assignment missing from worker logs' >&2; exit 1; }
pod_json=$(kubectl -n "$APP_NAMESPACE" get pod "$pod" -o json)
created=$(jq -er '.metadata.creationTimestamp' <<<"$pod_json")
started=$(jq -er --arg name "$container" '.status.containerStatuses[] | select(.name == $name) | .state.running.startedAt' <<<"$pod_json")
python3 - "$created" "$started" "$assigned" "$ready" <<'PY'
import sys
from datetime import datetime

created, started, assigned, ready = (datetime.fromisoformat(value.replace('Z', '+00:00')) for value in sys.argv[1:])
print(f"Pod creation to first Kafka assignment: {(assigned - created).total_seconds():.1f}s")
print(f"Worker container start to first Kafka assignment: {(assigned - started).total_seconds():.1f}s")
print(f"Pod creation to worker ready to process: {(ready - created).total_seconds():.1f}s")
print(f"Worker container start to ready to process: {(ready - started).total_seconds():.1f}s")
PY
