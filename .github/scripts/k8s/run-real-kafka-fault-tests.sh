#!/usr/bin/env bash
set -euo pipefail

: "$KAFKA_NAMESPACE"
: "$KAFKA_RELEASE_NAME"

# Run only in the disposable CI K3s cluster, using its existing Kafka.
name=streams-kafka-recovery
bootstrap="$KAFKA_RELEASE_NAME.$KAFKA_NAMESPACE.svc.cluster.local:9092"
image=ghcr.io/manticoresoftware/streams/worker-kafka-test:ci
kubectl -n "$KAFKA_NAMESPACE" get service "$KAFKA_RELEASE_NAME" -o json |
  jq -e '.spec.ports | any(.port == 9092)' >/dev/null

kubectl -n "$KAFKA_NAMESPACE" delete job "$name" --ignore-not-found --wait=true >/dev/null
kubectl -n "$KAFKA_NAMESPACE" apply -f - <<YAML
apiVersion: batch/v1
kind: Job
metadata:
  name: $name
spec:
  backoffLimit: 0
  activeDeadlineSeconds: 360
  ttlSecondsAfterFinished: 600
  template:
    spec:
      restartPolicy: Never
      containers:
        - name: tests
          image: $image
          imagePullPolicy: Never
          command: ["/worker.test"]
          args: ["-test.v", "-test.run=^TestRealKafkaDeliveryRecovery$", "-test.timeout=5m"]
          env:
            - name: STREAMS_REAL_KAFKA_BROKERS
              value: "$bootstrap"
            - name: STREAMS_JSLT_BUNDLE
              value: /usr/src/myapp/jslt-bundle.js
          resources:
            requests:
              memory: 128Mi
              cpu: 100m
            limits:
              memory: 768Mi
              cpu: '2'
YAML

status=1
for _ in $(seq 1 120); do
  state=$(kubectl -n "$KAFKA_NAMESPACE" get job "$name" -o json)
  if jq -e '(.status.succeeded // 0) >= 1' <<<"$state" >/dev/null; then
    status=0
    break
  fi
  if jq -e '(.status.failed // 0) >= 1' <<<"$state" >/dev/null; then
    break
  fi
  sleep 3
done
kubectl -n "$KAFKA_NAMESPACE" logs -l "job-name=$name" --all-containers --prefix --tail=-1 || true
if ((status != 0)); then
  kubectl -n "$KAFKA_NAMESPACE" describe job "$name" || true
  kubectl -n "$KAFKA_NAMESPACE" get pods -l "job-name=$name" -o wide || true
  exit 1
fi
kubectl -n "$KAFKA_NAMESPACE" delete job "$name" --wait=false >/dev/null
echo 'Real Kafka fault/replay integration tests passed'
