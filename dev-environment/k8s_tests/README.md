# Development-server K3d validation

Run the CI-equivalent Kubernetes deployment and Kafka E2E check in K3d on the amd64 development server over SSH.

Run the commands below from this `dev-environment/k8s_tests` directory.

## Configure

```sh
cp .env.example .env
```

Set `DEV_CLUSTER_IP`, `DEV_CLUSTER_USER`, and `CLUSTER_NAME` in `.env`. SSH uses the agent or `~/.ssh/config`; set `DEV_CLUSTER_SSH_KEY` only when a specific key file is needed.

The remote user must have Docker, Java 21, Maven, Helm, `kubectl`, and K3d. The runner adds `$HOME/.local/bin` to the remote `PATH`, which is where the development-server K3d, Helm, and kubectl tools were installed. It uses an isolated empty Docker configuration at `$HOME/.local/share/manticore-streams/docker-anonymous` so stale private registry credentials cannot block public base-image pulls. No sudo is required.

## Development-server commands

```sh
./dev-server-k3d.sh up
./dev-server-k3d.sh build
./dev-server-k3d.sh deploy
./dev-server-k3d.sh test
```

Every command uses the SSH target from `.env`; this runner never operates a local cluster. Commands other than `down` first synchronize the current checkout to `DEV_CLUSTER_WORKDIR`.

`up` first checks the remote K3d state. When the configured cluster is already running, it returns immediately without synchronizing the checkout, building images, or deploying workloads.

| Command | Purpose |
| --- | --- |
| `up` | Create the configured K3d cluster if needed, or start it when stopped, and wait for its node to become Ready. It does not build or deploy. |
| `build` | Build the six CI images on the development server without changing the cluster. |
| `deploy` | Import previously built images and install or upgrade Kafka and Streams. Run `build` first after code changes. |
| `test` | Run the CI Cluster PHPUnit suite, create its pipeline, publish the Kafka fixture, and verify the exact output count. |
| `status` | Show cluster nodes and the Kafka and Streams workloads. |
| `kubectl <args>` | Run `kubectl` against the remote K3d context without manually handling its kubeconfig. |
| `down` | Delete the configured K3d cluster and all Kubernetes data in it. |

## Debug pods

After `deploy` or while a failing `test` leaves the cluster up, use the runner as a remote `kubectl` proxy. It uses the remote `k3d-$CLUSTER_NAME` context and allocates a TTY for an interactive shell:

```sh
./dev-server-k3d.sh kubectl get pods -A -o wide
./dev-server-k3d.sh kubectl -n manticore-streams get events --sort-by=.lastTimestamp
./dev-server-k3d.sh kubectl -n manticore-streams logs deployment/manticore-streams-manticoresearch-ui --all-containers --tail=200
./dev-server-k3d.sh kubectl -n manticore-streams exec -it <pod-name> -c manticoresearch-ui -- sh
./dev-server-k3d.sh kubectl -n kafka logs my-kafka-controller-0 -c kafka --tail=200
```

To delete the configured K3d cluster and its workloads, persistent volumes, and Kubernetes data, run:

```sh
./dev-server-k3d.sh down
```

This runs `k3d cluster delete "$CLUSTER_NAME"` on the remote server and prints a ten-second heartbeat while deletion is in progress. It then removes and verifies any residual cluster Docker resources. It does not require sudo. It retains unrelated Docker images and local diagnostic artifacts, but the K3d cluster and all data stored in it are removed. Recreate it with `./dev-server-k3d.sh up`, then run `build` and `deploy`.

Use `./dev-server-k3d.sh --help` to display the command synopsis.
