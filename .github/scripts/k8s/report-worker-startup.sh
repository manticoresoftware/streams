#!/usr/bin/env bash
set -euo pipefail

# The E2E pipeline has a single replica and a single assigned partition.
pod="${PIPELINE}-0"
pod_json=$(kubectl -n "$APP_NAMESPACE" get pod "$pod" -o json)
container=$(jq -er '.spec.containers[] | select(.name | endswith("-worker")) | .name' <<<"$pod_json")
worker_api="/api/v1/namespaces/$APP_NAMESPACE/pods/$pod:8081/proxy"
kubectl get --raw "$worker_api/ready" >/dev/null
metrics=$(kubectl get --raw "$worker_api/metrics")
grep -q '^# HELP worker_batch_size ' <<<"$metrics"

logs=$(kubectl -n "$APP_NAMESPACE" logs "$pod" -c "$container" --timestamps)
assigned=$(awk '/Kafka partitions assigned after/ {print $1; exit}' <<<"$logs")
test -n "$assigned" || { echo 'First Kafka assignment missing from worker logs' >&2; exit 1; }
created=$(jq -er '.metadata.creationTimestamp' <<<"$pod_json")
started=$(jq -er --arg name "$container" '.status.containerStatuses[] | select(.name == $name) | .state.running.startedAt' <<<"$pod_json")
python3 - "$created" "$started" "$assigned" <<'PY'
import sys
from datetime import datetime

created, started, assigned = (datetime.fromisoformat(value.replace('Z', '+00:00')) for value in sys.argv[1:])
print(f"Pod creation to first Kafka assignment: {(assigned - created).total_seconds():.1f}s")
print(f"Worker container start to first Kafka assignment: {(assigned - started).total_seconds():.1f}s")
PY
