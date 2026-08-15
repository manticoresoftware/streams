#!/usr/bin/env bash
set -euxo pipefail

kubectl -n "$KAFKA_NAMESPACE" apply -f - <<EOF
apiVersion: v1
kind: Pod
metadata:
  name: ${KAFKA_FIXTURE_PRODUCER_POD}
  labels:
    app.kubernetes.io/name: kafka-fixture-producer
spec:
  restartPolicy: Never
  containers:
    - name: producer
      image: docker.io/bitnamilegacy/kafka:4.0.0-debian-12-r10
      command: ["sh", "-c", "sleep infinity"]
      resources:
        requests:
          cpu: 250m
          memory: 512Mi
        limits:
          cpu: "1"
          memory: 1Gi
EOF
kubectl -n "$KAFKA_NAMESPACE" wait --for=condition=Ready "pod/${KAFKA_FIXTURE_PRODUCER_POD}" --timeout="${TIMEOUT_SECONDS}s"
kubectl -n "$KAFKA_NAMESPACE" cp dev-environment/kafka/test_data.tar.gz "${KAFKA_FIXTURE_PRODUCER_POD}:/tmp/test_data.tar.gz"
kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_FIXTURE_PRODUCER_POD" -- tar -xzf /tmp/test_data.tar.gz -C /tmp
fixture_records=$(kubectl -n "$KAFKA_NAMESPACE" exec "$KAFKA_FIXTURE_PRODUCER_POD" -- sh -c 'wc -l < /tmp/test_data.json')
echo "FIXTURE_RECORDS=${fixture_records}" >> "$GITHUB_ENV"
