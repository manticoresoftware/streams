# Installation

Manticore Streams consumes Apache Kafka topics. Before installing it, make sure that Kafka is reachable from the Kubernetes namespace where Manticore Streams will run. See [Kafka setup](InstallFromScratch/Kafka.md) for a basic Kubernetes example.

## Requirements

- Kubernetes
- Helm 3
- An ingress controller when `ingress.enabled` is left at its default of `true`
- An Apache Kafka cluster reachable from the Manticore Streams workloads

## Download the chart

Download the `streams-<version>.tgz` asset for the desired version from [GitHub Releases](https://github.com/manticoresoftware/streams/releases). The package contains matching chart and image versions. Images are public at `ghcr.io/manticoresoftware/streams`, so no image-pull secret is required.

## Install with Helm

Choose a release name, namespace, administrator credentials, and ingress hostname. The following command creates the namespace when it does not already exist:

```sh
helm install manticore-streams ./streams-<version>.tgz \
  --namespace manticore-streams \
  --create-namespace \
  --set ui.admin.email='admin@example.com' \
  --set ui.admin.pass='change-this-password' \
  --set ingress.hosts[0].host='streams.example.com' \
  --set ingress.hosts[0].paths[0]='/'
```

To deploy without an ingress controller, add `--set ingress.enabled=false` and expose the UI service using the mechanism appropriate for the cluster.

Check deployment status:

```sh
kubectl get pods --namespace manticore-streams
```

Once the UI is available, sign in using the administrator credentials and configure Kafka sources, destinations, and streams.

## Custom values

To keep installation settings in a file, put them in `values.yaml` and pass the file to Helm:

```sh
helm install manticore-streams ./streams-<version>.tgz \
  --namespace manticore-streams \
  --create-namespace \
  --values values.yaml
```

See [Helm chart variables](HelmVariables.md) for the available settings.

## Upgrade and uninstall

Upgrade an existing deployment with a downloaded release asset:

```sh
helm upgrade manticore-streams ./streams-<version>.tgz \
  --namespace manticore-streams \
  --reuse-values
```

Remove the release with:

```sh
helm uninstall manticore-streams --namespace manticore-streams
```