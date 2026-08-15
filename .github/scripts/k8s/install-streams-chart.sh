#!/usr/bin/env bash
set -euo pipefail

export TIMEOUT_SECONDS=600
bash .github/scripts/k8s/install-kafka.sh
tag=$(awk '/^appVersion:/ { print $2; exit }' helm-chart/Chart.yaml)
ci_overrides=(
  --set ingress.enabled=false
  --set podSecurityPolicy.enabled=false
  --set-json 'imagePullSecrets=[]'
  --set 'worker.resources.requests.cpu=250m'
  --set 'worker.resources.requests.memory=512Mi'
  --set 'worker.resources.limits.cpu=1'
  --set 'worker.resources.limits.memory=1Gi'
  --set-string "ui.image.tag=${tag}"
  --set-string "ui.nginx.image.tag=${tag}"
  --set-string "scaler.php.image.tag=${tag}"
  --set-string "worker.image.tag=${tag}"
  --set-string "manticore.image.tag=${tag}"
  --set-string "rulesChecker.image.tag=${tag}"
)
helm template "$RELEASE_NAME" helm-chart --kube-version v1.32.5 "${ci_overrides[@]}" > artifacts-rendered-helm.yaml
mkdir -p artifacts
helm upgrade --install "$RELEASE_NAME" helm-chart --namespace "$APP_NAMESPACE" --create-namespace --wait --timeout 600s "${ci_overrides[@]}" 2>&1 | tee artifacts/helm-upgrade.log
