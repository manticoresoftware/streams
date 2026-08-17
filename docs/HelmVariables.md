# Helm Variables

### UI section
Parameter | Description | Default
--------- | ----------- | -------
`ui.userId` | Unix user id for UI container | `81`
`ui.admin.email` | User email which will created at first init | `manticore@example.com`
`ui.admin.pass` | User password which will created at first init | `changeme947`
`ui.database.name` | MYSQL Database name | `manticore_kafka_ui`
`ui.database.name` | MYSQL user name | `ms`
`ui.database.secret` | MYSQL user password | `mysqlSecret`
`ui.image.repository` | UI image url | `ghcr.io/manticoresoftware/streams/ui`
`ui.image.imagePullPolicy` | UI image pull policy. Switch to `Always` if you need to pull new image each pos restart | `IfNotPresent`
`ui.nginx.image.repository` | Nginx sidecar image url. Must contain UI public assets; the old `nginxinc/nginx-unprivileged` image is not supported | `ghcr.io/manticoresoftware/streams/ui-nginx`
`ui.nginx.image.tag` | Nginx sidecar image tag. Defaults to chart app version when omitted | ``
`ui.nginx.image.imagePullPolicy` | Nginx sidecar image pull policy | `IfNotPresent`
`ui.migrationWait.maxAttempts` | Maximum UI init-container checks while waiting for migrations to complete | `120`
`ui.migrationWait.sleepSeconds` | Seconds between UI init-container migration checks | `5`
`ui.service.port` | UI service port | `80`
`ui.service.targetPort` | UI service target port | `8080`
`ui.service.loadBalancer.enabled` | Enable Load Balancer for UI service | `false`
`ui.service.loadBalancer.ip` | Enable Load Balancer for UI service | `192.168.0.1`
`ui.volumeClaimTemplates.size` | Size for UI volume | `200Mi`
`ui.resources` | Allow to specify UI limits and requests | `{}`

### MYSQL section
Parameter | Description | Default
--------- | ----------- | -------
`mysql.userId` | Unix user id for MYSQL container | `999`
`mysql.image.repository` | MYSQL image url | `docker.io/mysql`
`mysql.image.tag` | MYSQL image tag | `5.6`
`mysql.image.imagePullPolicy` | MYSQL image pull policy | `IfNotPresent`
`mysql.service.port` | MYSQL service port | `3306`
`mysql.volumeClaimTemplates.size` | Size for MYSQL volume | `200Mi`
`mysql.resources` | Allow to specify MYSQL limits and requests | `{}`


### Scaler section
Parameter | Description | Default
--------- | ----------- | -------
`scaler.image.repository` | Scaler image url | `ghcr.io/manticoresoftware/streams/scaler`
`scaler.image.imagePullPolicy` | Scaler image pull policy | `IfNotPresent`
`scaler.service.port` | Scaler service port | `80`
`scaler.service.targetPort` | Scaler service target port | `8080`
`scaler.resources` | Allow to specify Scaler limits and requests | `{}`


### Columnar section
Parameter | Description | Default
--------- | ----------- | -------
`columnar.enabled` | Specify is Columnar metrics storage enabled | `true`
`columnar.ttl` | Columnar metrics retention period | `31`
`columnar.image.repository` | Columnar image url | `manticoresearch/manticore`
`columnar.image.imagePullPolicy` | Columnar image pull policy | `IfNotPresent`
`columnar.service.port` | Columnar service port | `9306`
`columnar.volumeClaimTemplates.size` | Size for metrics storage volume | `4Gi`
`columnar.resources` | Allow to specify metrics storage limits and requests | `{}`
`columnar.userId` | Unix user id for Columnar container | `999`

### Worker section
Parameter | Description | Default
--------- | ----------- | -------
`worker.image.repository` | Worker image url | `ghcr.io/manticoresoftware/streams/worker`
`worker.image.imagePullPolicy` | Worker image pull policy | `IfNotPresent`
`worker.processedMeasureTime` | Value in seconds for measuring processing. Allow to change pipieline scaling sensitivity. Less value - more quick, better - slowly  | `60`
`worker.maxQueryProcessingTime` | Value in seconds for measuring max query processing time. Allow to change pipieline scaling sensitivity. Less value - more quick, better - slowly  | `10`
`worker.maxKafkaMessage` | Set max message size in bytes which Worker can handle and send to Kafka | `998000`
`worker.skipExceededMessages` | Set exceeded message behaviour. Does we DON'T stop processing and exit if we get message bigger than max message size | `true`
`worker.resources` | Allow to specify Worker limits and requests | `{}`


### Manticore section
Parameter | Description | Default
--------- | ----------- | -------
`manticore.image.repository` | Manticore image url | `ghcr.io/manticoresoftware/streams/manticore`
`manticore.image.imagePullPolicy` | Manticore image pull policy | `IfNotPresent`
`manticore.service.port` | Manticore service port | `9306`
`manticore.volumeClaimTemplates.size` | Size for Manticore volume | `1Gi`
`manticore.resources` | Allow to specify Manticore limits and requests | `{}`


### Rules checker section
Parameter | Description | Default
--------- | ----------- | -------
`rulesChecker.image.repository` | Rules checker image url | `ghcr.io/manticoresoftware/streams/rules_checker`
`rulesChecker.image.imagePullPolicy` | Rules checker image pull policy | `IfNotPresent`
`rulesChecker.service.port` | Rules checker service port | `80`
`rulesChecker.service.targetPort` | Rules checker service target port | `8080`
`rulesChecker.resources` | Allow to specify Rules checker limits and requests | `{}`

### Ingress section
Parameter | Description | Default
--------- | ----------- | -------
`ingress.enabled` | Define is Ingress enabled | `true`
`ingress.annotations` | Ingress annotations | `kubernetes.io/ingress.class: nginx` `nginx.ingress.kubernetes.io/proxy-body-size: 16m`
`ingress.hosts[0].host` | Ingress host | `mks.manticoresearch.com`
`ingress.hosts[0].paths[0]` | Ingress path | `/`

### PSP section
Parameter | Description | Default
--------- | ----------- | -------
`podSecurityPolicy.enabled` | Define is pod security policy enabled | `false`
`podSecurityPolicy.annotations` | Define is pod security policy annotations | `{}`
