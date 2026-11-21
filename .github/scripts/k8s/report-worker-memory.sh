#!/usr/bin/env bash
set -euo pipefail

# Measure actual process memory rather than Go's allocated-heap metric.
# Called at readiness and after Kafka processing using the same worker pod.
stage="${1:?usage: report-worker-memory.sh <readiness|after-kafka>}"
pod="${PIPELINE:?PIPELINE is required}-0"
pod_json=$(kubectl -n "$APP_NAMESPACE" get pod "$pod" -o json)
container=$(jq -er '.spec.containers[] | select(.name | endswith("-worker")) | .name' <<<"$pod_json")
status=$(kubectl -n "$APP_NAMESPACE" exec "$pod" -c "$container" -- cat /proc/1/status)

rss_kb=$(awk '/^VmRSS:/ {print $2; exit}' <<<"$status")
hwm_kb=$(awk '/^VmHWM:/ {print $2; exit}' <<<"$status")
test -n "$rss_kb" && test -n "$hwm_kb"

awk -v stage="$stage" -v rss="$rss_kb" -v hwm="$hwm_kb" 'BEGIN {
  printf "Go worker process memory (%s): RSS %.2f MiB; peak RSS %.2f MiB\n", stage, rss / 1024, hwm / 1024
}'

# RSS includes live Go objects, runtime heaps that have not been returned to the
# OS, stacks, and mapped pages. Compare it with the existing heap-objects gauge
# to distinguish retained heap memory from garbage collector timing.
worker_api="/api/v1/namespaces/$APP_NAMESPACE/pods/$pod:8081/proxy/metrics"
if metrics=$(kubectl get --raw "$worker_api" 2>/dev/null); then
  heap_bytes=$(awk '$1 ~ /^worker_consumption_ram\\{/ {print $2; exit}' <<<"$metrics")
  if [[ "$heap_bytes" =~ ^[0-9]+([.][0-9]+)?$ ]] && [[ "$heap_bytes" != "0" ]]; then
    awk -v stage="$stage" -v heap="$heap_bytes" 'BEGIN {
      printf "Go worker live heap objects (%s): %.2f MiB (last metrics sample)\n", stage, heap / 1048576
    }'
  fi
fi
