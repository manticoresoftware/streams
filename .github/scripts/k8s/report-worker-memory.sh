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
