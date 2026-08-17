# Development-server K3d CI validation

`dev-environment/k8s_tests/dev-server-k3d.sh` reproduces the Kubernetes E2E path in `.github/workflows/ci.yml` on the amd64 development server over SSH. It is a remote-only runner: it does not create, build, deploy, or delete a local Mac K3d cluster.

## Prerequisites

Create the ignored target configuration:

```bash
cp dev-environment/k8s_tests/.env.example dev-environment/k8s_tests/.env
```

Set the server IP address, SSH user, and K3d cluster name. SSH uses the agent or `~/.ssh/config`; set `DEV_CLUSTER_SSH_KEY` only when an explicit private-key path is needed. `DEV_CLUSTER_WORKDIR` defaults to a disposable remote checkout at `/tmp/manticore-streams-k3s`.

The remote user needs Docker, Java 21, Maven, Helm, `kubectl`, and K3d. The runner adds `$HOME/.local/bin` to the remote `PATH`, uses an isolated empty Docker configuration at `$HOME/.local/share/manticore-streams/docker-anonymous` so stale credentials cannot block public base-image pulls, and synchronizes the current local working tree (excluding `.git`, artifacts, and the local `.env`). No sudo is required.

## Workflow

Run the commands from `dev-environment/k8s_tests`:

```bash
./dev-server-k3d.sh up
./dev-server-k3d.sh build
./dev-server-k3d.sh deploy
./dev-server-k3d.sh test
```

`up` creates the configured K3d cluster if necessary, or starts it when stopped, then waits for its node to become Ready. It does not build images or deploy workloads.

When the configured cluster is already running, `up` exits immediately without synchronizing the checkout or changing the cluster.

`build` compiles the Java worker and builds the six `linux/amd64` CI images on the development server. `deploy` imports those previously built images, installs or upgrades the pinned Kafka chart and Streams Helm chart, and waits for workloads. `deploy` requires a cluster created by `up` and images created by `build`.

`test` runs the Kubernetes-only Cluster PHPUnit suite, including its `cluster_testing` seed, pipeline creation, Kafka fixture publishing, and the exact 9,418-record output assertion from CI.

## Debugging pods

Use the runner as a remote `kubectl` proxy after `deploy`, or while a failing `test` leaves the cluster running. It uses the remote `k3d-$CLUSTER_NAME` context; interactive `exec` sessions receive a TTY:

```bash
./dev-server-k3d.sh kubectl get pods -A -o wide
./dev-server-k3d.sh kubectl -n manticore-streams get events --sort-by=.lastTimestamp
./dev-server-k3d.sh kubectl -n manticore-streams logs deployment/manticore-streams-manticoresearch-ui --all-containers --tail=200
./dev-server-k3d.sh kubectl -n manticore-streams exec -it <pod-name> -c manticoresearch-ui -- sh
./dev-server-k3d.sh kubectl -n kafka logs my-kafka-controller-0 -c kafka --tail=200
```

## Investigation and reset

```bash
./dev-server-k3d.sh status
./dev-server-k3d.sh down
```

`status` shows the remote cluster nodes and Kafka/Streams workloads. Use the `kubectl` proxy for events, pod descriptions, logs, and interactive shells.

`down` runs `k3d cluster delete "$CLUSTER_NAME"` as the remote user and prints a ten-second heartbeat while it waits. It removes and verifies any remaining container, network, or K3d image volume. It deletes workloads, persistent volumes, and Kubernetes data in that K3d cluster, while retaining unrelated Docker images. Recreate the stand with `up`, then run `build` and `deploy`.
