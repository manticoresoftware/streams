#!/usr/bin/env bash
set -euo pipefail

: "${APP_NAMESPACE:?}"
: "${KAFKA_NAMESPACE:?}"
: "${KAFKA_POD:?}"
: "${KAFKA_FIXTURE_PRODUCER_POD:?}"
: "${PIPELINE:?}"
: "${RELEASE_NAME:?}"
: "${EXPECTED_RECORDS:?}"
: "${FIXTURE_RECORDS:?}"
: "${TIMEOUT_SECONDS:?}"

BENCH_REPETITIONS="${BENCH_REPETITIONS:-1}"
BENCH_BATCH_SIZES="${BENCH_BATCH_SIZES:-5000}"
BENCH_SCENARIOS="${BENCH_SCENARIOS:-plain jslt}"
BENCH_SMALL_INPUT_DOCS="${BENCH_SMALL_INPUT_DOCS:-1000}"
[[ "$BENCH_REPETITIONS" =~ ^[1-3]$ ]] || { echo "repetitions must be 1-3" >&2; exit 1; }
[[ "$FIXTURE_RECORDS" == "43403" && "$EXPECTED_RECORDS" == "9418" ]] || { echo "unexpected fixture totals" >&2; exit 1; }
[[ "$BENCH_SMALL_INPUT_DOCS" =~ ^[1-9][0-9]*$ ]] && (( BENCH_SMALL_INPUT_DOCS < FIXTURE_RECORDS )) || {
  echo "small input must be 1..$((FIXTURE_RECORDS-1))" >&2; exit 1;
}

artifact_dir=artifacts/full-worker-benchmark
mkdir -p "$artifact_dir"
metrics="$artifact_dir/measurements.csv"
printf '%s\n' 'scenario,batch_size,implementation,repetition,input_docs,output_records,wall_s,docs_per_s,first_s,peak_rss_mib,end_rss_mib,cpu_s,heap_mib' > "$metrics"

kafka_bin=/opt/bitnami/kafka/bin
kafka() {
  local tool=$1
  shift
  kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- "$kafka_bin/$tool" --bootstrap-server localhost:9092 "$@"
}
topic_offset() {
  kafka kafka-get-offsets.sh --topic "$1" | awk -F: '$2 == 0 {total += $3} END {print total+0}'
}
group_offset() {
  local group=$1 topic=$2
  kafka kafka-consumer-groups.sh --describe --group "$group" 2>/dev/null | awk -v topic="$topic" '$2 == topic && $3 == "0" {print $4; exit}'
}
topic_create() {
  kafka kafka-topics.sh --create --if-not-exists --topic "$1" --partitions 1 --replication-factor 1
}
worker_name=$(kubectl -n "$APP_NAMESPACE" get statefulset "$PIPELINE" -o json |
  jq -er '.spec.template.spec.containers[].name | select(endswith("-worker"))')
image_tag=$(awk '/^appVersion:/ { print $2; exit }' helm-chart/Chart.yaml)
test -n "$worker_name" && test -n "$image_tag"
jslt_expression=$(cat .github/scripts/k8s/benchmark-jslt.jslt)
base_run_id="${GITHUB_RUN_ID:-local}"

scale_down() {
  kubectl -n "$APP_NAMESPACE" scale "statefulset/$PIPELINE" --replicas=0
  kubectl -n "$APP_NAMESPACE" wait --for=delete "pod/$PIPELINE-0" --timeout=180s
}

# Stop the previous worker and Manticore sidecar before editing the pod
# template. Recreate both for each measurement so the PQ state and lifecycle
# are comparable. The PVC and percolate rules persist across repetitions.
configure_worker() {
  local implementation=$1 input=$2 output=$3 group=$4 batch=$5 scenario=$6
  local image probe
  if [[ "$implementation" == "java" ]]; then
    image="ghcr.io/manticoresoftware/streams/worker-java-benchmark:$image_tag"
    probe="/metrics" # Original Java HTTPServer has /metrics but no /live.
  else
    image="ghcr.io/manticoresoftware/streams/worker:$image_tag"
    probe="/live"
  fi
  local containers patch
  containers=$(kubectl -n "$APP_NAMESPACE" get "statefulset/$PIPELINE" -o json |
    jq -c --arg worker "$worker_name" --arg implementation "$implementation" \
      --arg image "$image" --arg probe "$probe" --arg input "$input" \
      --arg output "$output" --arg group "$group" --arg batch "$batch" \
      --arg scenario "$scenario" --arg jslt "$jslt_expression" '
    .spec.template.spec.containers |
    map(if .name == $worker then
      .image = $image |
      .imagePullPolicy = "IfNotPresent" |
      (if $implementation == "java"
       then .command = ["java", "-XX:MaxRAMPercentage=60.0", "-cp", "/KafkaHandler.jar", "manticore.Main"] | .args = []
       else del(.command) | del(.args) end) |
      .readinessProbe.httpGet.path = $probe |
      .livenessProbe.httpGet.path = $probe |
      .startupProbe.httpGet.path = $probe |
      .env = ((.env | map(select(
          .name != "KAFKA_INPUT_TOPIC" and
          .name != "KAFKA_OUTPUT_TOPIC" and
          .name != "KAFKA_GROUP_NAME" and
          .name != "MAX_BATCH_SIZE" and
          .name != "MIN_THREADS" and
          .name != "MAX_THREADS" and
          .name != "OUTPUT_DOCS" and
          .name != "JSLT_CONFIG" and
          .name != "LOG_LEVEL"
        ))) +
        [
          {"name":"KAFKA_INPUT_TOPIC","value":$input},
          {"name":"KAFKA_OUTPUT_TOPIC","value":$output},
          {"name":"KAFKA_GROUP_NAME","value":$group},
          {"name":"MAX_BATCH_SIZE","value":$batch},
          {"name":"MIN_THREADS","value":"1"},
          {"name":"MAX_THREADS","value":"1"},
          {"name":"OUTPUT_DOCS","value":"0011"},
          {"name":"LOG_LEVEL","value":"WARN"}
        ] +
        (if $scenario == "jslt" then [{"name":"JSLT_CONFIG","value":$jslt}] else [] end))
    else . end)')
  test -n "$containers" && test "$containers" != "null"
  patch=$(jq -nc --argjson value "$containers" '[{"op":"replace","path":"/spec/template/spec/containers","value":$value}]')
  kubectl -n "$APP_NAMESPACE" patch "statefulset/$PIPELINE" --type=json -p "$patch"
}

# Load backlog before starting either worker. A temporary Kafka consumer
# initializes the group so resetting its offset to zero is deterministic;
# without this, Java/Go both default new groups to the high watermark.
prepare_backlog() {
  local input=$1 output=$2 group=$3 input_docs=$4
  topic_create "$input"
  topic_create "$output"
  kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_FIXTURE_PRODUCER_POD" -- sh -c \
    "head -n '$input_docs' /tmp/test_data.json | timeout 180s $kafka_bin/kafka-console-producer.sh --bootstrap-server my-kafka.kafka.svc.cluster.local:9092 --topic '$input'"
  local input_offset
  input_offset=$(topic_offset "$input")
  [[ "$input_offset" == "$input_docs" ]] || {
    echo "$input: $input_offset input records instead of $input_docs" >&2; return 1;
  }
  [[ "$(topic_offset "$output")" == "0" ]] || { echo "nonempty output topic $output" >&2; return 1; }

  timeout 75s kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- \
    "$kafka_bin/kafka-console-consumer.sh" --bootstrap-server localhost:9092 \
    --topic "$input" --group "$group" --from-beginning \
    --max-messages 1 --timeout-ms 45000 >/dev/null
  local attempt reset_result offset
  for attempt in $(seq 1 25); do
    reset_result=$(kafka kafka-consumer-groups.sh --group "$group" --topic "$input" \
      --reset-offsets --to-earliest --execute 2>&1) || true
    offset=$(group_offset "$group" "$input" || true)
    if [[ "$offset" == "0" ]]; then
      printf 'Prepared %s group %s at committed offset 0, backlog %s\n' "$input" "$group" "$input_docs"
      return 0
    fi
    sleep 3
  done
  echo "Cannot initialize group $group to earliest offset; last reset: $reset_result; offset: $offset" >&2
  return 1
}

# Stop on worker restart: outputs from multiple container instances do not
# provide comparable elapsed time, CPU usage or peak RSS measurements.
worker_restarts() {
  kubectl -n "$APP_NAMESPACE" get "pod/$PIPELINE-0" -o json 2>/dev/null |
    jq -r --arg worker "$worker_name" '[.status.containerStatuses[]? | select(.name == $worker) | .restartCount][0] // 0'
}

# Poll Kafka from the same K3s cluster. Capture first and final output times
# before starting the worker; kubectl rollout status alone would miss the
# earliest output when the backlog is already present.
monitor_output() {
  local input=$1 group=$2 input_docs=$3 output=$4 result_file=$5 expected_output=$6
  local deadline=$((SECONDS + TIMEOUT_SECONDS))
  local n first=0 current_ms restarts committed
  while (( SECONDS < deadline )); do
    n=$(topic_offset "$output" || echo 0)
    current_ms=$(date +%s%3N)
    restarts=$(worker_restarts || true)
    if [[ "$restarts" =~ ^[0-9]+$ ]] && (( restarts > 0 )); then
      echo "Worker $worker_name restarted $restarts time(s) while processing $output; benchmark invalid" >&2
      kubectl -n "$APP_NAMESPACE" logs "pod/$PIPELINE-0" -c "$worker_name" --previous --tail=250 >&2 || true
      return 1
    fi
    if (( n > 0 && first == 0 )); then
      first=$current_ms
    fi
    if (( expected_output > 0 )); then
      if (( n > expected_output )); then
        echo "Unexpected output for $output: $n > $expected_output" >&2
        return 1
      fi
      if (( n == expected_output )); then
        printf '%s %s %s\n' "$first" "$current_ms" "$n" > "$result_file"
        return 0
      fi
    else
      # For the 1000-document small-batch fixture the exact output count is
      # learned from Java, then compared with Go. A committed input offset
      # means the worker has already delivered its corresponding output.
      committed=$(group_offset "$group" "$input" || true)
      if [[ "$committed" == "$input_docs" ]]; then
        if (( n == 0 )); then
          echo "No output records for $output; choose a representative fixture" >&2
          return 1
        fi
        printf '%s %s %s\n' "$first" "$current_ms" "$n" > "$result_file"
        return 0
      fi
    fi
    sleep 1
  done
  echo "Timeout on $output, output=$n (expected=$expected_output), committed=${committed:-unknown}/$input_docs" >&2
  return 1
}

# The pod has a fresh cgroup on every repetition. CPU usage covers the
# worker and its subprocesses; in Java this includes any PHP child process.
read_memory() {
  local pod="$PIPELINE-0" status cpu metrics_text
  status=$(kubectl -n "$APP_NAMESPACE" exec "$pod" -c "$worker_name" -- cat /proc/1/status)
  peak_rss_kb=$(awk '/^VmHWM:/ {print $2; exit}' <<<"$status")
  end_rss_kb=$(awk '/^VmRSS:/ {print $2; exit}' <<<"$status")
  [[ "$peak_rss_kb" =~ ^[0-9]+$ && "$end_rss_kb" =~ ^[0-9]+$ ]] || return 1
  cpu=$(kubectl -n "$APP_NAMESPACE" exec "$pod" -c "$worker_name" -- cat /sys/fs/cgroup/cpu.stat 2>/dev/null || true)
  cpu_usec=$(awk '$1 == "usage_usec" {print $2; exit}' <<<"$cpu")
  metrics_text=$(kubectl get --raw "/api/v1/namespaces/$APP_NAMESPACE/pods/$pod:8081/proxy/metrics" 2>/dev/null || true)
  heap_bytes=$(awk '$1 ~ /^worker_consumption_ram/ {print $2; exit}' <<<"$metrics_text")
}

run_worker() {
  local scenario=$1 batch=$2 rep=$3 implementation=$4
  local tag="${base_run_id}-${scenario}-${batch}-${rep}-${implementation}"
  local input="bench-in-$tag" output="bench-out-$tag" group="bench-group-$tag"
  # Kafka topics and consumer groups are ASCII, unique for every run.
  echo "=== full-worker benchmark: $scenario / max_batch=$batch / rep=$rep / $implementation ==="

  local input_docs="$FIXTURE_RECORDS" expected_output="$EXPECTED_RECORDS"
  if [[ "$batch" == "2" ]]; then
    input_docs="$BENCH_SMALL_INPUT_DOCS"
    expected_output=0
  fi
  scale_down
  prepare_backlog "$input" "$output" "$group" "$input_docs"
  configure_worker "$implementation" "$input" "$output" "$group" "$batch" "$scenario"
  local times_file="$artifact_dir/$tag.times"
  monitor_output "$input" "$group" "$input_docs" "$output" "$times_file" "$expected_output" &
  local monitor_pid=$!
  kubectl -n "$APP_NAMESPACE" scale "statefulset/$PIPELINE" --replicas=1
  kubectl -n "$APP_NAMESPACE" rollout status "statefulset/$PIPELINE" --timeout="${TIMEOUT_SECONDS}s"
  wait "$monitor_pid"
  local first_ms end_ms output_count started_iso started_ms completed_offset
  read -r first_ms end_ms output_count < "$times_file"
  [[ "$output_count" =~ ^[1-9][0-9]*$ ]] || { echo "invalid output count $output_count" >&2; return 1; }
  started_iso=$(kubectl -n "$APP_NAMESPACE" get "pod/$PIPELINE-0" -o json |
    jq -er --arg worker "$worker_name" '.status.containerStatuses[] |
      select(.name == $worker) | .state.running.startedAt')
  started_ms=$(python3 - "$started_iso" <<'PY'
from datetime import datetime
import sys
dt = datetime.fromisoformat(sys.argv[1].replace("Z", "+00:00"))
print(int(dt.timestamp() * 1000))
PY
)
  (( started_ms > 0 && first_ms >= started_ms && end_ms >= first_ms )) || {
    echo "Invalid Kafka timestamps: container=$started_ms first=$first_ms end=$end_ms" >&2
    return 1
  }
  local attempt
  for attempt in $(seq 1 30); do
    completed_offset=$(group_offset "$group" "$input" || true)
    [[ "$completed_offset" == "$input_docs" ]] && break
    sleep 2
  done
  [[ "$completed_offset" == "$input_docs" ]] || {
    echo "$group committed $completed_offset instead of $input_docs" >&2; return 1;
  }

  read_memory
  local output_file="$artifact_dir/$scenario-$batch-$rep-$implementation.jsonl"
  timeout 150s kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- \
    "$kafka_bin/kafka-console-consumer.sh" --bootstrap-server localhost:9092 \
    --topic "$output" --partition 0 --offset earliest --max-messages "$output_count" \
    --timeout-ms 90000 > "$output_file"
  [[ "$(wc -l < "$output_file")" == "$output_count" ]] || {
    echo "$output: captured wrong number of output messages" >&2; return 1;
  }
  python3 - "$metrics" "$scenario" "$batch" "$implementation" "$rep" \
    "$input_docs" "$output_count" "$started_ms" "$first_ms" "$end_ms" \
    "$peak_rss_kb" "$end_rss_kb" "$cpu_usec" "$heap_bytes" <<'PY'
import csv
import sys
(path, scenario, batch, implementation, rep, docs, output_count, started, first, end,
 peak, rss, cpu, heap) = sys.argv[1:]
elapsed = (int(end)-int(started))/1000
if elapsed <= 0:
    raise SystemExit("invalid elapsed time")
with open(path, "a", newline="", encoding="utf-8") as stream:
    csv.writer(stream).writerow([
        scenario, batch, implementation, rep, docs, output_count, f"{elapsed:.3f}",
        f"{int(docs)/elapsed:.2f}", f"{(int(first)-int(started))/1000:.3f}",
        f"{int(peak)/1024:.2f}", f"{int(rss)/1024:.2f}",
        f"{int(cpu)/1_000_000:.3f}" if cpu.isdigit() else "",
        f"{float(heap)/1048576:.2f}" if heap and heap.lower() != "nan" else "",
    ])
PY
  echo "Completed $tag: wall=$((end_ms-started_ms)) ms, input=$input_docs, output=$output_count, peakRSS=$peak_rss_kb KiB"
}

case "$BENCH_SCENARIOS" in
  plain) scenarios=(plain) ;;
  jslt) scenarios=(jslt) ;;
  all|"plain jslt") scenarios=(plain jslt) ;;
  *) echo "unknown scenario '$BENCH_SCENARIOS'" >&2; exit 1 ;;
esac

for scenario in "${scenarios[@]}"; do
  for batch in $BENCH_BATCH_SIZES; do
    [[ "$batch" == "2" || "$batch" == "5000" ]] || { echo "unsupported max batch $batch" >&2; exit 1; }
    for ((rep=1; rep<=BENCH_REPETITIONS; rep++)); do
      # Alternate order to reduce bias from warmed shared PQ caches.
      implementations=(java go)
      if (( rep % 2 == 0 )); then implementations=(go java); fi
      for implementation in "${implementations[@]}"; do
        run_worker "$scenario" "$batch" "$rep" "$implementation"
      done
      # Java determines the small-fixture output count; exact multiset parity
      # is still required when the Go run comes first on even repetitions.
      expected_output=$(awk '{print $3}' "$artifact_dir/${base_run_id}-$scenario-$batch-$rep-java.times")
      python3 .github/scripts/k8s/compare-full-worker-output.py \
        --java "$artifact_dir/$scenario-$batch-$rep-java.jsonl" \
        --go "$artifact_dir/$scenario-$batch-$rep-go.jsonl" \
        --expected "$expected_output" --scenario "$scenario/$batch/$rep" \
        --report "$artifact_dir/$scenario-$batch-$rep-comparison.json"
      # The output multiset digest is retained, but full Kafka payloads are not
      # uploaded with the benchmark artifact.
      rm "$artifact_dir/$scenario-$batch-$rep-"*.jsonl
    done
  done
done

python3 .github/scripts/k8s/report-full-worker-benchmark.py "$metrics" > "$artifact_dir/summary.md"
cat "$artifact_dir/summary.md"
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  cat "$artifact_dir/summary.md" >> "$GITHUB_STEP_SUMMARY"
fi
