# Manticore Streams

Manticore Streams is a Kubernetes application for filtering Apache Kafka topics with Manticore Search. Define filtering rules in the UI, process incoming documents at scale, and write matched documents to another Kafka topic.

## Requirements

- A Kubernetes cluster
- Helm 3
- An Apache Kafka cluster reachable from the Kubernetes workloads

The release chart uses public images from `ghcr.io/manticoresoftware/streams`; no image-pull secret is required.

## Install

1. Download the `streams-<version>.tgz` chart asset for the desired version from the [GitHub Releases page](https://github.com/manticoresoftware/streams/releases).
2. Install it with Helm. Choose a release name, namespace, administrator credentials, and the hostname served by your ingress controller:

```sh
helm install manticore-streams ./streams-<version>.tgz \
  --namespace manticore-streams \
  --create-namespace \
  --set ui.admin.email='admin@example.com' \
  --set ui.admin.pass='change-this-password' \
  --set ingress.hosts[0].host='streams.example.com' \
  --set ingress.hosts[0].paths[0]='/'
```

To deploy without an ingress controller, add `--set ingress.enabled=false` and expose the UI service by the mechanism appropriate for your cluster.

Verify that the workloads are running:

```sh
kubectl get pods --namespace manticore-streams
```

After signing in to the UI, configure sources, destinations, and streams. The Kafka brokers and topics you configure must be reachable from the Manticore Streams namespace.

## Upgrade and uninstall

Download the desired release asset, then upgrade the existing release with the same values:

```sh
helm upgrade manticore-streams ./streams-<version>.tgz \
  --namespace manticore-streams \
  --reuse-values
```

To remove the release:

```sh
helm uninstall manticore-streams --namespace manticore-streams
```

## Documentation

See [docs/README.md](docs/README.md) for the full documentation index, including chart values, Kafka setup guidance, and operating Manticore Streams.