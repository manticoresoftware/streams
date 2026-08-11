# Install Apache Kafka

??? why do we want to include it into our docs? Isn't there an existing article on the internet about installing kafka in kubernetes? In general let's assume user already has Kafka somewhere. We just need to explain how to connect to it.

If you don't have it, you install and run Kafka Server

`helm repo add bitnami https://charts.bitnami.com/bitnami`

`helm install my-kafka --namespace kafka bitnami/kafka`

Don't worry about topics, Stream handler app create it automatically.

In that case Kafka host will `my-kafka.kafka.svc.cluster.local` (inner Kubernetes). It will need us on goals creation stage

Also you can get Kafka Pod hostname via command
```
kubectl -n kafka exec -it my-kafka -- hostname -f
```


If you see that either `pod/my-kafka-0` or `pod/my-kafka-zookeeper-0` is not running in `kubectl get all -n kafka` try:
```
kubectl edit statefulset.apps/my-kafka-zookeeper -n kafka
kubectl edit statefulset.apps/my-kafka -n kafka
```
To make it run under root:
```
           securityContext:
             fsGroup: 0
             runAsUser: 0
```

**Exposing**

Sometimes need to expose Kafka outside cluster, in case when Manticore Streams deployed on a different cluster.

For exposing we've modify Kafka helm chart config:

```
externalAccess.enabled=true
externalAccess.service.type=NodePort
externalAccess.serivce.nodePorts[0]='30001'
```
It's work only if we will edit `values.yaml`.
Don't try to specify parameters by adding `--set` arguments to `helm install` command. This will not work!!
