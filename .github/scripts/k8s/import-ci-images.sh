#!/usr/bin/env bash
set -euxo pipefail

tag=$(awk '/^appVersion:/ { print $2; exit }' helm-chart/Chart.yaml)
for image_archive in ci-images/*.tar.gz; do
  gzip -dc "$image_archive" | docker load
done
components=(manticore scaler rules_checker worker ui ui-nginx)
for component in "${components[@]}"; do
  docker image inspect "streams/${component}:ci" >/dev/null
  docker tag "streams/${component}:ci" "ghcr.io/manticoresoftware/streams/${component}:${tag}"
done
for component in "${components[@]}"; do
  image="ghcr.io/manticoresoftware/streams/${component}:${tag}"
  docker image save "$image" | sudo k3s ctr -n k8s.io images import -
  sudo k3s ctr -n k8s.io images list -q | grep -Fx "$image"
done
