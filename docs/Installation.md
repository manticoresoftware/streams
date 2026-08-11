# Installation

Manticore Streams for now supports only Apache Kafka as a source of an input stream. Read
this [article](InstallFromScratch/Kafka.md) about installation of Apache Kafka

---

# Cloning

### ⚠️ Attention! ⚠️

You can't use **GIT clone**. You must always **download archive** from git.

Helm use commit tags which don't available via git clone!!

## Container images

The chart defaults to private images hosted at `ghcr.io/manticoresoftware/streams`. Before installing, create an image-pull secret in the release namespace with a GitHub token that has `read:packages` access:

`kubectl create secret docker-registry registry-manticore-streams --namespace={namespace} --docker-server=ghcr.io --docker-username={github-user} --docker-password={github-token}`

To use a differently named secret, set `imagePullSecrets` in your values file.

## Helm

<!-- example helm-install -->
For installing, you must specify login and password for the admin, and ingress host of you project

Important parameters there:

* ui.admin.email - email for super admin auth
* ui.admin.pass - password for super admin auth
* ingress.hosts - set host for UI access

<!-- intro -->

### Helm 3

<!-- request Helm 3 -->

```
helm install {name} --namespace {namesace} \
--set ui.admin.email="user@manticoresearch.com" \
--set ui.admin.pass="myNewPassword" \
--set ingress.hosts[0].host="kafka.manticoresearch.com" \
--set ingress.hosts[0].paths[0]="/" ./helm-chart
```

<!-- intro -->

### Helm 2

<!-- request Helm 2 -->

```
helm install --name {name} --namespace {namesace} \
--set ui.admin.email="user@manticoresearch.com" \
--set ui.admin.pass="myNewPassword" \
--set ingress.hosts[0].host="kafka.manticoresearch.com" \
--set ingress.hosts[0].paths[0]="/" ./helm-chart
```

<!-- end -->
<!-- example helm-filled-install -->
Or if you've udpated `values.yaml` you can use just:

<!-- intro -->

### Helm 3

<!-- request Helm 3 -->

```helm install {name} --namespace {namesace} ./helm-chart```

<!-- intro -->

### Helm 2

<!-- request Helm 2 -->

```helm install --name {name} --namespace {namesace} ./helm-chart```

<!-- end -->

**That's all**

___

Check for deploy:

```
$ kubectl get po -n {namespace}
NAME                                    READY   STATUS    RESTARTS   AGE
mkc-columnar-0                          1/1     Running   0          1m
mkc-scaler-69554f869b-g7b5f             1/1     Running   0          1m
mkc-ui-0                                1/1     Running   0          1m
mkc-ui-mysql-0                          1/1     Running   0          1m
```

After our project has been deployed we can start to create streams
