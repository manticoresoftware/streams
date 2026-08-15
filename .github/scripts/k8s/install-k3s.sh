#!/usr/bin/env bash
set -euxo pipefail

curl -fsSL https://get.k3s.io | INSTALL_K3S_VERSION="$K3S_VERSION" sh -s - server --disable=traefik --disable=servicelb
sudo chmod 644 /etc/rancher/k3s/k3s.yaml
echo 'KUBECONFIG=/etc/rancher/k3s/k3s.yaml' >> "$GITHUB_ENV"
end=$(( $(date +%s) + TIMEOUT_SECONDS ))
until sudo k3s kubectl get nodes --no-headers 2>/dev/null | grep -q .; do
  [ "$(date +%s)" -lt "$end" ] || { echo 'K3s did not register a node in time' >&2; sudo journalctl -u k3s --no-pager; exit 1; }
  sleep 2
done
sudo k3s kubectl wait --for=condition=Ready node --all --timeout="${TIMEOUT_SECONDS}s"
