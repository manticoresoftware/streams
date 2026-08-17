#!/usr/bin/env bash
set -euo pipefail

: "${K3S_VERSION:?K3S_VERSION must be set}"
: "${K3S_BINARY_SHA256:?K3S_BINARY_SHA256 must be set}"

workdir=$(mktemp -d)
trap 'rm -rf "$workdir"' EXIT
k3s_binary="$workdir/k3s"
curl --fail --location --proto '=https' --tlsv1.2 --retry 3 \
  --output "$k3s_binary" \
  "https://github.com/k3s-io/k3s/releases/download/${K3S_VERSION}/k3s"
printf '%s  %s\n' "$K3S_BINARY_SHA256" "$k3s_binary" | sha256sum --check --status

sudo install -m 0755 "$k3s_binary" /usr/local/bin/k3s
sudo tee /etc/systemd/system/k3s.service >/dev/null <<'EOF'
[Unit]
Description=Lightweight Kubernetes
After=network-online.target
Wants=network-online.target

[Service]
Type=notify
KillMode=process
Delegate=yes
LimitNOFILE=1048576
LimitNPROC=infinity
LimitCORE=infinity
TasksMax=infinity
TimeoutStartSec=0
Restart=always
RestartSec=5s
ExecStart=/usr/local/bin/k3s server --disable=traefik --disable=servicelb

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload
sudo systemctl enable --now k3s

kubeconfig="${RUNNER_TEMP:-/tmp}/k3s.yaml"
sudo install -m 0600 -o "$(id -u)" -g "$(id -g)" /etc/rancher/k3s/k3s.yaml "$kubeconfig"
echo "KUBECONFIG=$kubeconfig" >> "$GITHUB_ENV"
end=$(( $(date +%s) + TIMEOUT_SECONDS ))
until sudo k3s kubectl get nodes --no-headers 2>/dev/null | grep -q .; do
  [ "$(date +%s)" -lt "$end" ] || { echo 'K3s did not register a node in time' >&2; sudo journalctl -u k3s --no-pager; exit 1; }
  sleep 2
done
sudo k3s kubectl wait --for=condition=Ready node --all --timeout="${TIMEOUT_SECONDS}s"
