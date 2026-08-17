# Installation

Manticore Streams for now supports only Apache Kafka as a source of an input stream. Read
this [article](InstallFromScratch/Kafka.md) about installation of Apache Kafka

---

# Cloning

### ⚠️ Attention! ⚠️

You can't use **GIT clone**. Download the `.tgz` chart asset from the GitHub Release for the desired version instead. The release package has matching chart and image versions already materialized.

## Container images

The chart uses public images hosted at `ghcr.io/manticoresoftware/streams`; no image-pull secret is required.

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
helm install {name} --namespace {namespace} \
--set ui.admin.email="user@manticoresearch.com" \
--set ui.admin.pass="myNewPassword" \
--set ingress.hosts[0].host="kafka.manticoresearch.com" \
--set ingress.hosts[0].paths[0]="/" ./manticoresearch-{version}.tgz
```

<!-- intro -->

### Helm 2

<!-- request Helm 2 -->

```
helm install --name {name} --namespace {namespace} \
--set ui.admin.email="user@manticoresearch.com" \
--set ui.admin.pass="myNewPassword" \
--set ingress.hosts[0].host="kafka.manticoresearch.com" \
--set ingress.hosts[0].paths[0]="/" ./manticoresearch-{version}.tgz
```

<!-- end -->
<!-- example helm-filled-install -->
The release package uses the `values.yaml` embedded when it was built. To use your own edited values file, pass it explicitly with `-f values.yaml`:

<!-- intro -->

### Helm 3

<!-- request Helm 3 -->

```helm install {name} --namespace {namespace} -f values.yaml ./manticoresearch-{version}.tgz```

<!-- intro -->

### Helm 2

<!-- request Helm 2 -->

```helm install --name {name} --namespace {namespace} -f values.yaml ./manticoresearch-{version}.tgz```

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
