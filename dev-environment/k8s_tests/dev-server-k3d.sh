#!/usr/bin/env bash
set -euo pipefail

# A remote-only K3d reproduction of the CI E2E environment on the amd64
# development server. This machine only synchronizes the checkout and invokes SSH.

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ENV_FILE=${DEV_CLUSTER_ENV_FILE:-"$SCRIPT_DIR/.env"}

if [ -f "$ENV_FILE" ]; then
  set -a
  # shellcheck disable=SC1090
  . "$ENV_FILE"
  set +a
fi

CLUSTER_MODE=${CLUSTER_MODE:-k3d}
CLUSTER_NAME=${CLUSTER_NAME:-manticore-streams-dev}
K3S_VERSION=${K3S_VERSION:-v1.32.5-k3s1}
APP_NAMESPACE=${APP_NAMESPACE:-manticore-streams}
KAFKA_NAMESPACE=${KAFKA_NAMESPACE:-kafka}
RELEASE_NAME=${RELEASE_NAME:-manticore-streams}
KAFKA_RELEASE_NAME=${KAFKA_RELEASE_NAME:-my-kafka}
TIMEOUT_SECONDS=${TIMEOUT_SECONDS:-600}
PIPELINE_CREATION_TIMEOUT_SECONDS=${PIPELINE_CREATION_TIMEOUT_SECONDS:-60}
EXPECTED_RECORDS=${EXPECTED_RECORDS:-9418}
KAFKA_POD=${KAFKA_POD:-my-kafka-controller-0}
KAFKA_FIXTURE_PRODUCER_POD=${KAFKA_FIXTURE_PRODUCER_POD:-kafka-fixture-producer}
UI_DEPLOYMENT=${UI_DEPLOYMENT:-manticore-streams-manticoresearch-ui}
UI_CONTAINER=${UI_CONTAINER:-manticoresearch-ui}
PLATFORM=${PLATFORM:-linux/amd64}
DEV_CLUSTER_WORKDIR=${DEV_CLUSTER_WORKDIR:-/tmp/manticore-streams-k3s}

components=(manticore scaler rules_checker worker ui ui-nginx)

die() {
  printf 'error: %s\n' "$*" >&2
  exit 1
}

require_commands() {
  local command commands=(docker helm kubectl k3d mvn python3)
  [ "$CLUSTER_MODE" = k3d ] || die "unsupported CLUSTER_MODE: $CLUSTER_MODE"
  for command in "${commands[@]}"; do
    command -v "$command" >/dev/null 2>&1 || die "required command is not installed: $command"
  done
  docker info >/dev/null 2>&1 || die 'Docker is not running'
}

java_home() {
  if [ -n "${JAVA_HOME:-}" ] && [ -x "$JAVA_HOME/bin/java" ]; then
    printf '%s\n' "$JAVA_HOME"
    return
  fi

  if [ "$(uname -s)" = Darwin ] && [ -x /usr/libexec/java_home ]; then
    /usr/libexec/java_home -v 21 2>/dev/null || die 'Java 21 is required; install it or set JAVA_HOME'
    return
  fi

  die 'JAVA_HOME must point to a Java 21 installation'
}

kubectl_dev() {
  kubectl --context "k3d-${CLUSTER_NAME}" "$@"
}

helm_dev() {
  helm --kube-context "k3d-${CLUSTER_NAME}" "$@"
}

image_tag() {
  if [ -n "${IMAGE_TAG:-}" ]; then
    printf '%s\n' "$IMAGE_TAG"
    return
  fi
  git -C "$ROOT" rev-parse --short HEAD
}

version() {
  local tag
  tag=$(image_tag)
  python3 - "$ROOT/helm-chart/Chart.yaml" "$tag" <<'PY'
import pathlib
import sys

chart = pathlib.Path(sys.argv[1])
short_sha = sys.argv[2]
source = chart.read_text()
token = '$Format:%h$'
if source.count(token) != 2:
    raise SystemExit(f'expected two development-version tokens, found {source.count(token)}')
for line in source.splitlines():
    if line.startswith('appVersion: '):
        base = line.removeprefix('appVersion: ').removesuffix(token)
        print(f'{base}{short_sha}')
        break
else:
    raise SystemExit('Chart.yaml has no appVersion')
PY
}

materialize_chart() {
  local destination=$1 tag=$2
  cp -a "$ROOT/helm-chart" "$destination"
  python3 - "$destination/Chart.yaml" "$tag" <<'PY'
import pathlib
import sys

chart = pathlib.Path(sys.argv[1])
short_sha = sys.argv[2]
source = chart.read_text()
token = '$Format:%h$'
if source.count(token) != 2:
    raise SystemExit(f'expected two development-version tokens, found {source.count(token)}')
chart.write_text(source.replace(token, short_sha))
PY
}

create_cluster() {
  if k3d cluster list -o json | python3 -c '
import json
import sys
name = sys.argv[1]
print("present" if any(cluster["name"] == name for cluster in json.load(sys.stdin)) else "")
' "$CLUSTER_NAME" | grep -qx present; then
    k3d cluster start "$CLUSTER_NAME"
    return
  fi

  k3d cluster create "$CLUSTER_NAME" \
    --image "rancher/k3s:${K3S_VERSION}" \
    --servers 1 \
    --agents 0 \
    --k3s-arg '--disable=traefik@server:*' \
    --k3s-arg '--disable=servicelb@server:*' \
    --wait
}

require_cluster() {
  if ! k3d cluster list -o json | python3 -c '
import json
import sys
name = sys.argv[1]
raise SystemExit(0 if any(cluster["name"] == name for cluster in json.load(sys.stdin)) else 1)
' "$CLUSTER_NAME"; then
    die "k3d cluster does not exist: $CLUSTER_NAME; run up first"
  fi
}

wait_for_node() {
  kubectl_dev wait --for=condition=Ready node --all --timeout="${TIMEOUT_SECONDS}s"
}

build_images() (
  local tag staging build_java_home
  tag=$(version)
  build_java_home=$(java_home)
  staging=$(mktemp -d)
  trap 'rm -rf "$staging"' EXIT

  (
    cd "$ROOT/sources/src"
    JAVA_HOME="$build_java_home" mvn clean compile assembly:single
  )

  cp -a "$ROOT/docker/worker" "$staging/worker"
  cp "$ROOT/sources/src/target/KafkaPublisher-1.0-SNAPSHOT-jar-with-dependencies.jar" "$staging/worker/KafkaHandler.jar"
  cp -a "$ROOT/docker/ui" "$staging/ui"
  cp -a "$ROOT/ui/." "$staging/ui/source/"
  cp -a "$ROOT/docker/ui-nginx" "$staging/ui-nginx"
  cp -a "$ROOT/ui/." "$staging/ui-nginx/source/"

  for component in manticore scaler rules_checker; do
    docker buildx build --platform "$PLATFORM" --load \
      -t "streams/${component}:ci" \
      -f "$ROOT/docker/${component}/Dockerfile" "$ROOT/docker/${component}"
  done
  for component in worker ui ui-nginx; do
    docker buildx build --platform "$PLATFORM" --load \
      -t "streams/${component}:ci" \
      -f "$staging/${component}/Dockerfile" "$staging/${component}"
  done

  for component in "${components[@]}"; do
    docker image inspect "streams/${component}:ci" >/dev/null
    docker tag "streams/${component}:ci" "ghcr.io/manticoresoftware/streams/${component}:${tag}"
  done
)

import_images() {
  local tag component
  tag=$(version)
  for component in "${components[@]}"; do
    local_image="ghcr.io/manticoresoftware/streams/${component}:${tag}"
    docker image inspect "$local_image" >/dev/null || die "image was not built: $local_image"
    k3d image import --cluster "$CLUSTER_NAME" "$local_image"
  done
}

chart_overrides() {
  local tag=$1
  printf '%s\n' \
    '--set' 'ingress.enabled=false' \
    '--set' 'podSecurityPolicy.enabled=false' \
    '--set' 'worker.resources.requests.cpu=250m' \
    '--set' 'worker.resources.requests.memory=512Mi' \
    '--set' 'worker.resources.limits.cpu=1' \
    '--set' 'worker.resources.limits.memory=1Gi' \
    '--set-string' "ui.image.tag=${tag}" \
    '--set-string' "ui.nginx.image.tag=${tag}" \
    '--set-string' "scaler.php.image.tag=${tag}" \
    '--set-string' "worker.image.tag=${tag}" \
    '--set-string' "manticore.image.tag=${tag}" \
    '--set-string' "rulesChecker.image.tag=${tag}"
}

deploy() {
  local tag chart
  tag=$(version)
  chart=$(mktemp -d)
  materialize_chart "$chart/chart" "$tag"
  overrides=()
  while IFS= read -r override; do
    overrides+=("$override")
  done < <(chart_overrides "$tag")

  export KAFKA_NAMESPACE KAFKA_RELEASE_NAME TIMEOUT_SECONDS
  (
    cd "$ROOT"
    bash .github/scripts/k8s/install-kafka.sh
  )
  helm_dev upgrade --install "$RELEASE_NAME" "$chart/chart" \
    --namespace "$APP_NAMESPACE" --create-namespace --wait --timeout "${TIMEOUT_SECONDS}s" \
    "${overrides[@]}"
  rm -rf "$chart"
}

wait_for_workloads() {
  local ui_pod
  kubectl_dev get namespace "$APP_NAMESPACE"
  kubectl_dev -n "$KAFKA_NAMESPACE" get pod "$KAFKA_POD"
  kubectl_dev -n "$APP_NAMESPACE" rollout status "deployment/${UI_DEPLOYMENT}" --timeout="${TIMEOUT_SECONDS}s"
  kubectl_dev -n "$APP_NAMESPACE" rollout status "deployment/${RELEASE_NAME}-manticoresearch-scaler" --timeout="${TIMEOUT_SECONDS}s"
  kubectl_dev -n "$APP_NAMESPACE" rollout status "statefulset/${RELEASE_NAME}-manticoresearch-columnar" --timeout="${TIMEOUT_SECONDS}s"
  kubectl_dev -n "$APP_NAMESPACE" rollout status "statefulset/${RELEASE_NAME}-manticoresearch-ui-mysql" --timeout="${TIMEOUT_SECONDS}s"
  ui_pod=$(kubectl_dev -n "$APP_NAMESPACE" get pods -l name=manticoresearch-ui -o jsonpath='{.items[0].metadata.name}')
  test -n "$ui_pod" || die 'UI pod was not found'
  printf '%s\n' "$ui_pod"
}

restore_ui_test_config() {
  local ui_pod=$1
  kubectl_dev -n "$APP_NAMESPACE" exec "$ui_pod" -c "$UI_CONTAINER" -- sh -c '
    sed -i "s/^APP_ENV=cluster_testing$/APP_ENV=production/" .env
    sed -i "/name=\"APP_ENV\"/ s/value=\"[^\"]*\"/value=\"testing\"/" phpunit.xml
  '
}

configure_ui_cluster_test_config() {
  local ui_pod=$1
  kubectl_dev -n "$APP_NAMESPACE" exec "$ui_pod" -c "$UI_CONTAINER" -- sh -c '
    sed -i "s/^APP_ENV=production$/APP_ENV=cluster_testing/" .env
    sed -i "/name=\"APP_ENV\"/ s/value=\"[^\"]*\"/value=\"cluster_testing\"/" phpunit.xml
  '
}

run_e2e() (
  local ui_pod pipeline input_start_offset fixture_records input_offset expected_input_offset start_offset current_offset end started next_report now group_members
  wait_for_workloads >/dev/null
  ui_pod=$(kubectl_dev -n "$APP_NAMESPACE" get pods -l name=manticoresearch-ui -o jsonpath='{.items[0].metadata.name}')
  test -n "$ui_pod" || die 'UI pod was not found'
  restore_ui_test_config "$ui_pod"
  trap 'restore_ui_test_config "$ui_pod" || true' EXIT
  kubectl_dev -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --create --if-not-exists --topic my-docs --partitions=1 --replication-factor=1
  kubectl_dev -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --create --if-not-exists --topic my-results --partitions=1 --replication-factor=1
  kubectl_dev -n "$APP_NAMESPACE" exec "$ui_pod" -c "$UI_CONTAINER" -- php artisan migrate:fresh --seed --force
  configure_ui_cluster_test_config "$ui_pod"
  kubectl_dev -n "$APP_NAMESPACE" exec "$ui_pod" -c "$UI_CONTAINER" -- php artisan db:seed --class='\TestDataSeeder' --force
  kubectl_dev -n "$APP_NAMESPACE" exec "$ui_pod" -c "$UI_CONTAINER" -- sh -c '
    test -x ./vendor/bin/phpunit
    php -d short_open_tag=off ./vendor/bin/phpunit --testsuite Cluster --list-tests --stderr | tee /tmp/cluster-test-list.txt
    grep -Fqx " - Tests\\Cluster\\ClusterTest::assignUser" /tmp/cluster-test-list.txt
    php -d short_open_tag=off ./vendor/bin/phpunit --do-not-cache-result --testsuite Cluster --stderr
  '

  started=$(date +%s)
  end=$(( started + PIPELINE_CREATION_TIMEOUT_SECONDS ))
  next_report=$started
  pipeline=''
  while [ "$(date +%s)" -lt "$end" ]; do
    pipeline=$(kubectl_dev -n "$APP_NAMESPACE" get statefulsets -l app.kubernetes.io/component=worker -o jsonpath='{range .items[*]}{.metadata.name}{end}')
    [ -n "$pipeline" ] && break
    now=$(date +%s)
    if [ "$now" -ge "$next_report" ]; then
      printf 'Pipeline creation: waiting (%ss elapsed, %ss remaining)\n' "$((now - started))" "$((end - now))"
      kubectl_dev -n "$APP_NAMESPACE" get statefulsets -l app.kubernetes.io/component=worker
      next_report=$((now + 30))
    fi
    sleep 5
  done
  [ -n "$pipeline" ] || die "Cluster test did not create a pipeline StatefulSet within ${PIPELINE_CREATION_TIMEOUT_SECONDS}s"
  kubectl_dev -n "$APP_NAMESPACE" rollout status "statefulset/$pipeline" --timeout="${TIMEOUT_SECONDS}s"

  end=$(( $(date +%s) + TIMEOUT_SECONDS ))
  next_report=0
  group_members=''
  while [ "$(date +%s)" -lt "$end" ]; do
    group_members=$(kubectl_dev -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-consumer-groups.sh --bootstrap-server localhost:9092 --describe --group ms_test_stream --members --verbose 2>&1 || true)
    if printf '%s\n' "$group_members" | grep -Eq 'my-docs(:0|\(0\))'; then
      printf 'Kafka consumer group ms_test_stream is assigned my-docs partition 0\n'
      break
    fi
    now=$(date +%s)
    if [ "$now" -ge "$next_report" ]; then
      printf 'Kafka consumer assignment: waiting for ms_test_stream to receive my-docs partition 0\n%s\n' "$group_members"
      next_report=$((now + 30))
    fi
    sleep 5
  done
  printf '%s\n' "$group_members" | grep -Eq 'my-docs(:0|\(0\))' || die "Kafka consumer group ms_test_stream was not assigned my-docs partition 0 within ${TIMEOUT_SECONDS}s"

  input_start_offset=$(kubectl_dev -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-get-offsets.sh --bootstrap-server localhost:9092 --topic my-docs | awk -F: 'END { print $3 }')
  start_offset=$(kubectl_dev -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-get-offsets.sh --bootstrap-server localhost:9092 --topic my-results | awk -F: 'END { print $3 }')
  kubectl_dev -n "$KAFKA_NAMESPACE" apply -f - <<EOF
apiVersion: v1
kind: Pod
metadata:
  name: ${KAFKA_FIXTURE_PRODUCER_POD}
spec:
  restartPolicy: Never
  containers:
    - name: producer
      image: docker.io/bitnamilegacy/kafka:4.0.0-debian-12-r10
      command: ["sh", "-c", "sleep infinity"]
      resources:
        requests: {cpu: 250m, memory: 512Mi}
        limits: {cpu: "1", memory: 1Gi}
EOF
  kubectl_dev -n "$KAFKA_NAMESPACE" wait --for=condition=Ready "pod/${KAFKA_FIXTURE_PRODUCER_POD}" --timeout="${TIMEOUT_SECONDS}s"
  kubectl_dev -n "$KAFKA_NAMESPACE" cp "$ROOT/dev-environment/kafka/test_data.tar.gz" "${KAFKA_FIXTURE_PRODUCER_POD}:/tmp/test_data.tar.gz"
  kubectl_dev -n "$KAFKA_NAMESPACE" exec "$KAFKA_FIXTURE_PRODUCER_POD" -- tar -xzf /tmp/test_data.tar.gz -C /tmp
  fixture_records=$(kubectl_dev -n "$KAFKA_NAMESPACE" exec "$KAFKA_FIXTURE_PRODUCER_POD" -- sh -c 'wc -l < /tmp/test_data.json')
  kubectl_dev -n "$KAFKA_NAMESPACE" exec "$KAFKA_FIXTURE_PRODUCER_POD" -- sh -c 'timeout 120s /opt/bitnami/kafka/bin/kafka-console-producer.sh --bootstrap-server my-kafka.kafka.svc.cluster.local:9092 --topic my-docs < /tmp/test_data.json'
  input_offset=$(kubectl_dev -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-get-offsets.sh --bootstrap-server localhost:9092 --topic my-docs | awk -F: 'END { print $3 }')
  expected_input_offset=$(( ${input_start_offset:-0} + fixture_records ))
  printf 'Kafka input: start=%s current=%s expected=%s\n' "${input_start_offset:-0}" "${input_offset:-0}" "$expected_input_offset"
  [ "${input_offset:-0}" -eq "$expected_input_offset" ] || die 'Kafka fixture was not fully published'

  end=$(( $(date +%s) + TIMEOUT_SECONDS ))
  while [ "$(date +%s)" -lt "$end" ]; do
    current_offset=$(kubectl_dev -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-get-offsets.sh --bootstrap-server localhost:9092 --topic my-results | awk -F: 'END { print $3 }')
    current_offset=${current_offset:-0}
    if [ "$current_offset" -eq $(( ${start_offset:-0} + EXPECTED_RECORDS )) ]; then
      printf 'Processed exactly %s records\n' "$EXPECTED_RECORDS"
      return
    fi
    printf 'Waiting for output records: start=%s current=%s expected=%s\n' "${start_offset:-0}" "$current_offset" "$EXPECTED_RECORDS"
    sleep 10
  done
  kubectl_dev -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-get-offsets.sh --bootstrap-server localhost:9092 --topic my-docs || true
  kubectl_dev -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-get-offsets.sh --bootstrap-server localhost:9092 --topic my-results || true
  kubectl_dev -n "$KAFKA_NAMESPACE" exec "$KAFKA_POD" -c kafka -- /opt/bitnami/kafka/bin/kafka-consumer-groups.sh --bootstrap-server localhost:9092 --describe --group ms_test_stream || true
  die "output offset did not reach $(( ${start_offset:-0} + EXPECTED_RECORDS )) within ${TIMEOUT_SECONDS}s"
)

run_remote_kubectl() {
  local target remote_command quoted_arguments
  local -a ssh_options=(ssh -o BatchMode=yes)

  shift
  [ "$#" -gt 0 ] || die 'usage: dev-server-k3d.sh kubectl <kubectl arguments>'
  [ -n "${DEV_CLUSTER_IP:-}" ] || die "DEV_CLUSTER_IP is required in $ENV_FILE"
  [ -n "${DEV_CLUSTER_USER:-}" ] || die "DEV_CLUSTER_USER is required in $ENV_FILE"
  command -v ssh >/dev/null 2>&1 || die 'required command is not installed: ssh'
  if [ -n "${DEV_CLUSTER_SSH_KEY:-}" ]; then
    [ -f "$DEV_CLUSTER_SSH_KEY" ] || die "DEV_CLUSTER_SSH_KEY does not exist: $DEV_CLUSTER_SSH_KEY"
    ssh_options+=(-i "$DEV_CLUSTER_SSH_KEY")
  fi
  if [ -t 0 ] && [ -t 1 ]; then
    ssh_options+=(-tt)
  fi

  target="${DEV_CLUSTER_USER}@${DEV_CLUSTER_IP}"
  printf -v quoted_arguments ' %q' "$@"
  # shellcheck disable=SC2016 # $HOME and $PATH must expand on the remote host.
  printf -v remote_command 'export PATH="$HOME/.local/bin:$PATH"; kubectl --context %q%s' "k3d-${CLUSTER_NAME}" "$quoted_arguments"
  "${ssh_options[@]}" "$target" "$remote_command"
}

run_remote() {
  local command=${1:-up} target ssh_transport remote_command remote_image_tag cluster_check status=0
  local -a ssh_options=(ssh -o BatchMode=yes)

  case "$command" in
    up|build|deploy|test|status|down) ;;
    *) die "remote command must be one of: up, build, deploy, test, status, down" ;;
  esac
  [ -n "${DEV_CLUSTER_IP:-}" ] || die "DEV_CLUSTER_IP is required in $ENV_FILE"
  [ -n "${DEV_CLUSTER_USER:-}" ] || die "DEV_CLUSTER_USER is required in $ENV_FILE"
  command -v ssh >/dev/null 2>&1 || die 'required command is not installed: ssh'
  if [ -n "${DEV_CLUSTER_SSH_KEY:-}" ]; then
    [ -f "$DEV_CLUSTER_SSH_KEY" ] || die "DEV_CLUSTER_SSH_KEY does not exist: $DEV_CLUSTER_SSH_KEY"
    ssh_options+=(-i "$DEV_CLUSTER_SSH_KEY")
  fi

  target="${DEV_CLUSTER_USER}@${DEV_CLUSTER_IP}"
  if [ "$command" = up ]; then
    # shellcheck disable=SC2016 # $HOME and $PATH must expand on the remote host.
    printf -v cluster_check 'export PATH="$HOME/.local/bin:$PATH"; k3d cluster list -o json | python3 -c %q %q' \
      'import json, sys
name = sys.argv[1]
clusters = json.load(sys.stdin)
for cluster in clusters:
    if cluster["name"] == name:
        servers = [node for node in cluster["nodes"] if node["role"] == "server"]
        raise SystemExit(0 if servers and all(node["State"]["Running"] for node in servers) else 1)
raise SystemExit(1)' "$CLUSTER_NAME"
    if "${ssh_options[@]}" "$target" "$cluster_check"; then
      printf 'Remote k3d cluster %s is already running; nothing to do.\n' "$CLUSTER_NAME"
      return
    fi
  fi
  if [ "$command" = down ]; then
    local delete_pid elapsed=0 cleanup_command verify_command
    printf 'Deleting remote K3d cluster %s and all of its data...\n' "$CLUSTER_NAME"
    "${ssh_options[@]}" "$target" "export PATH=\"\$HOME/.local/bin:\$PATH\"; k3d cluster delete $(printf '%q' "$CLUSTER_NAME")" &
    delete_pid=$!
    while kill -0 "$delete_pid" 2>/dev/null; do
      sleep 10
      elapsed=$((elapsed + 10))
      if kill -0 "$delete_pid" 2>/dev/null; then
        printf 'Still deleting remote cluster (%ss elapsed)...\n' "$elapsed"
      fi
    done
    if ! wait "$delete_pid"; then
      die "remote K3d cluster deletion failed after ${elapsed}s; run status to inspect the remaining resources"
    fi
    # shellcheck disable=SC2016 # $cluster must expand on the remote host.
    printf -v cleanup_command 'set -e; cluster=%q; containers=$(docker ps -aq --filter "label=k3d.cluster=$cluster"); if [ -n "$containers" ]; then printf "Removing remaining cluster container(s): %%s\\n" "$containers"; docker rm -f $containers; fi; if docker network inspect "k3d-$cluster" >/dev/null 2>&1; then printf "Removing remaining cluster network k3d-%%s\\n" "$cluster"; docker network rm "k3d-$cluster"; fi; if docker volume inspect "k3d-$cluster-images" >/dev/null 2>&1; then printf "Removing remaining cluster image volume k3d-%%s-images\\n" "$cluster"; docker volume rm "k3d-$cluster-images"; fi' "$CLUSTER_NAME"
    printf 'Checking for remaining remote Docker resources...\n'
    if ! "${ssh_options[@]}" "$target" "$cleanup_command"; then
      die 'remote K3d deletion left Docker resources that could not be removed'
    fi
    # shellcheck disable=SC2016 # $HOME and $PATH must expand on the remote host.
    if "${ssh_options[@]}" "$target" "export PATH=\"\$HOME/.local/bin:\$PATH\"; k3d cluster list -o json | python3 -c 'import json, sys; raise SystemExit(0 if any(cluster[\"name\"] == sys.argv[1] for cluster in json.load(sys.stdin)) else 1)' $(printf '%q' "$CLUSTER_NAME")"; then
      die 'remote K3d reported success, but the cluster still exists'
    fi
    # shellcheck disable=SC2016 # $cluster must expand on the remote host.
    printf -v verify_command 'cluster=%q; test -z "$(docker ps -aq --filter "label=k3d.cluster=$cluster")" && ! docker network inspect "k3d-$cluster" >/dev/null 2>&1 && ! docker volume inspect "k3d-$cluster-images" >/dev/null 2>&1' "$CLUSTER_NAME"
    if ! "${ssh_options[@]}" "$target" "$verify_command"; then
      die 'remote K3d cluster metadata was removed, but Docker resources remain'
    fi
    printf 'Remote K3d cluster %s was deleted.\n' "$CLUSTER_NAME"
    return
  fi

  command -v rsync >/dev/null 2>&1 || die 'required command is not installed: rsync'
  "${ssh_options[@]}" "$target" "export PATH=\"\$HOME/.local/bin:\$PATH\"; mkdir -p $(printf '%q' "$DEV_CLUSTER_WORKDIR")"
  printf -v ssh_transport '%q ' "${ssh_options[@]}"
  printf 'Synchronizing checkout to %s...\n' "$target"
  rsync -az --progress --delete \
    --exclude .git --exclude artifacts --exclude dev-environment/k8s_tests/.env \
    -e "$ssh_transport" "$ROOT/" "${target}:${DEV_CLUSTER_WORKDIR}/"
  printf 'Checkout synchronized.\n'

  remote_image_tag=$(git -C "$ROOT" rev-parse --short HEAD) || die 'remote mode requires a Git checkout on the local machine'
  # shellcheck disable=SC2016 # $HOME and $PATH must expand on the remote host.
  printf -v remote_command 'export PATH="$HOME/.local/bin:$PATH" DOCKER_CONFIG="$HOME/.local/share/manticore-streams/docker-anonymous"; mkdir -p "$DOCKER_CONFIG"; if [ -z "${JAVA_HOME:-}" ] && [ -x "$HOME/.local/jdks/temurin-21/bin/java" ]; then export JAVA_HOME="$HOME/.local/jdks/temurin-21"; fi; cd %q && REMOTE_EXECUTION=1 CLUSTER_MODE=k3d CLUSTER_NAME=%q IMAGE_TAG=%q bash dev-environment/k8s_tests/dev-server-k3d.sh %q' \
    "$DEV_CLUSTER_WORKDIR" "$CLUSTER_NAME" "$remote_image_tag" "$command"
  if "${ssh_options[@]}" "$target" "$remote_command"; then
    :
  else
    status=$?
  fi

  return "$status"
}

usage() {
  cat <<'EOF'
Usage: dev-environment/k8s_tests/dev-server-k3d.sh <command>

Commands:
  up           Create or start the remote k3d cluster and wait for its node to become Ready.
  build        Build the six CI images on the development server.
  deploy       Import existing images and install/upgrade Kafka and Streams on the development server.
  test         Run the CI Kubernetes application and Kafka fixture checks on the development server.
  status       Show remote nodes and application/Kafka workloads.
  kubectl      Run kubectl arguments against the remote K3d cluster.
  down         Delete the configured remote k3d cluster and all of its data.

Environment overrides: CLUSTER_NAME, K3S_VERSION, PLATFORM, TIMEOUT_SECONDS.
Remote configuration: dev-environment/k8s_tests/.env (see .env.example).
EOF
}

remote_main() {
  local command=${1:-}
  case "$command" in
    up)
      require_commands
      create_cluster
      wait_for_node
      ;;
    build) require_commands; build_images ;;
    deploy)
      require_commands
      require_cluster
      wait_for_node
      import_images
      deploy
      wait_for_workloads >/dev/null
      ;;
    test)
      require_commands
      run_e2e
      ;;
    status)
      require_commands
      kubectl_dev get nodes -o wide
      kubectl_dev -n "$KAFKA_NAMESPACE" get all,cm,pvc -o wide
      kubectl_dev -n "$APP_NAMESPACE" get all,cm,pvc -o wide
      ;;
    -h|--help|help|'') usage ;;
    *) die "unknown command: $command" ;;
  esac
}

main() {
  local command=${1:-}
  if [ "${REMOTE_EXECUTION:-0}" = 1 ]; then
    remote_main "$@"
    return
  fi

  case "$command" in
    up|build|deploy|test|status|down) run_remote "$command" ;;
    kubectl) run_remote_kubectl "$@" ;;
    -h|--help|help|'') usage ;;
    *) die "unknown command: $command" ;;
  esac
}

main "$@"
