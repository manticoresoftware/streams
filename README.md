# Kafka UI

**Attention!!!** Min kubernetes version: 1.20
### Kafka Installation

If you don't have it, you install and run Kafka Server

```bash
helm repo add bitnami https://charts.bitnami.com/bitnami
helm --namespace kafka install --set listeners.client.protocol=PLAINTEXT --set listeners.controller.protocol=PLAINTEXT my-kafka bitnami/kafka
```

Don't worry about topics, Stream handler app create it automatically.

In that case Kafka host will `my-kafka.kafka.svc.cluster.local` (inner Kubernetes). It will need us on goals creation stage 

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

For exposing Kafka outside cluster we've modify Kafka helm chart config:

```
externalAccess.enabled=true
externalAccess.service.type=NodePort
externalAccess.serivce.nodePorts[0]='30001'
```
It's work only if we will edit `values.yaml`. 
Don't try to specify parameters by adding `--set` arguments to `helm install` command. This will not work!!

### MKC Installation

When Kafka server are runned, we can start deploying `MKC` chart:

The chart defaults to private images hosted at `ghcr.io/manticoresoftware/streams`. Before installing, create an image-pull secret in the release namespace with a GitHub token that has `read:packages` access:

`kubectl create secret docker-registry registry-manticore-streams --namespace={namespace} --docker-server=ghcr.io --docker-username={github-user} --docker-password={github-token}`

To use a differently named secret, set `imagePullSecrets` in your values file.

For installing you must specify auth l/p and ingress host of you project

**Helm 2**

`helm install --name {name} --namespace {namesace}
--set ui.admin.email="user@manticoresearch.com" 
--set ui.admin.pass="myNewPassword"
--set ingress.hosts[0].host="kafka.manticoresearch.com" 
--set ingress.hosts[0].paths[0]="/" ./manticoresearch-{version}.tgz`

**Helm 3**

`helm install {name} --namespace {namesace}
--set ui.admin.email="user@manticoresearch.com" 
--set ui.admin.pass="myNewPassword"
--set ingress.hosts[0].host="kafka.manticoresearch.com" 
--set ingress.hosts[0].paths[0]="/" ./manticoresearch-{version}.tgz`



The release package uses the `values.yaml` embedded when it was built. To use your own edited values file, pass it explicitly with `-f values.yaml`:

For **Helm 2**
 
`helm install --name {name} --namespace {namespace} -f values.yaml ./manticoresearch-{version}.tgz`

For **Helm 3**

`helm install {name} --namespace {namespace} -f values.yaml ./manticoresearch-{version}.tgz`

Important parameters there:

* ui.admin.email - email for super admin auth
* ui.admin.pass  - password for super admin auth
* ingress.hosts  - set host for UI access

That's all


### Removing

For **Helm 3** removing just run `helm uninstall {name} -n {namespace}`

**Helm 2** can't handle dynamically created pods, do after removing we must clean up k8s:

**Warning!!!**: don't use these commands in "default" namespace. 
In case if you installed the chart to that namespace, you must remove it remove "by hand"!!
```
kubectl -n {namespace} delete all --all
kubectl -n {namespace} delete cm --all
kubectl -n {namespace} delete pvc --all
```

In some cases helm can not correctly remove chart and on reinstalling you can get error:

```
Error: rendered manifests contain a resource that already exists. Unable to continue with install: 
existing resource conflict: kind: SomeResouce, namespace: , name: ResourceName
``` 

You must remove it yourself: 

```
kubectl delete all -n {namespace} --all
kubectl delete pvc -n {namespace} --all
kubectl delete sa ui-admin-sa-{namespace} -n {namespace}
kubectl delete ClusterRole ui-admin-role-{namespace} -n {namespace}
kubectl delete ClusterRoleBinding ui-admin-{namespace} -n {namespace}
kubectl delete ingress kafka-pq -n {namespace}
```


### Our local cluster tests:
1) Must make vpn to kuber01
2) Run 
```
kafkacat -b kafka01.example.com:9092,kafka02.example.com:9092,kafka03.example.com:9092 \
-G mkc20200116 sina.firehose.out | kafkacat -b 78.47.167.154:30001 -t my-docs
```

### Api tokens

For using API first generate token from `/tokens` section.

After, add headers to your request: 
```
Authorization: Bearer $TOKEN
Accept: application/json
```

Endpoints:
```
/api/admin/source/getList
/api/admin/source/add
/api/admin/source/delete
/api/admin/destination/getList
/api/admin/destination/add
/api/admin/destination/delete
/api/manager/getRulesList
/api/manager/addRule
/api/manager/deleteRule
/api/admin/process/add
/api/admin/process/assignUser
/api/admin/process/unassignUser
/api/admin/process/remove/{id}
