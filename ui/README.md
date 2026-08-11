API reference

| route | parameters | description |
|---|---|---|
| GET\POST /api/admin/users/get | `start: 0` -> Start offset <br> `length: 50` -> Limit <br> `search[value]:` -> String for searching <br> `search[regex]: false` -> Search by RegEX  | Draw users list | 
| GET\POST /api/admin/users/add |             `email: 1@1.com` -> User email. Required, must be unique <br> `name: username` -> Username. Required, min 2, unique, regex:`[a-zA-Z0-9]` <br> `token` -> Api Bearer token. Min 32 chars <br> `password` -> Required. Min 5 chars | Add new user |
| GET\POST /api/admin/users/edit | `user_id` -> Required <br>`role_id` -> Required. admin:1, manager:2 | Switch user between admin and manager |
| GET\POST /api/admin/users/ban | `user_id` -> Required | Enable\Disable user |
| GET\POST /api/admin/users/delete | `user_id` -> Required | Force user removing |
| GET\POST /api/admin/token/reissue | `user_id` -> Required <br>`token`-> Required | Reissue API token |
| GET\POST /api/admin/process/resolveHosts | `source` -> Required. Id of source record <br> `destination` -> Required. Id of destination record | Return expanded data of hosts | 
| GET\POST /api/admin/process/parseSchema | `host` -> Required. Kafka host <br>`group`-> Kafka consumer group <br>`topic`-> Topic | Starts job witch get schema from Kafka Topic | 
| GET\POST /api/admin/process/getSchema | - | Return results of `/api/admin/process/parseSchema` |
| GET\POST /api/admin/process/extendedInfo/{id} | - | Return extended process settings |
| GET\POST /api/admin/process/getSuspendList | `process_id` -> Required | Draw list of streams which can be suspended |
| GET\POST /api/admin/process/getResumeList | `process_id` -> Required | Draw list of streams which can be resumed |
| GET\POST /api/admin/process/suspend | `streamId` -> Required. Stream id | Suspends selected stream |
| GET\POST /api/admin/process/resume | `streamId` -> Required. Stream id | Resumes selected stream |
| GET\POST /api/admin/process/streams/get | `user_id` -> Required. `process_id` -> int | Return data of user streaming |
| GET\POST /api/admin/process/get | - | Return list of existing processes |
| GET\POST /api/admin/process/add | `name`-> Required. Name of new process <br>`source_id`->Required. Id of source host<br> `destination_id`->Required. Id of destination host <br> `attrs` -> Required. JSON encoded. Array of fields to transform. Also manticore fields <br> `output_docs`    -> Required. Binary hash (0110). First - return modified names, Second - original names, Third - other names, Four - include info about matching query <br>`max_batch_size` -> Required. Max batch of messages for sending to Manticore <br>`max_threads`    -> Required. Max threads of Manticore <br> `query_complexity_validation` -> Integer (0,1). Enables query validation <br> `language` -> Inploded by coma list of languages <br> `jslt_conf` -> Config for JSLT | Add new process |
| GET\POST /api/admin/process/assign | `process_id`-> Required. Process for assign <br> `assign_user` -> Required. User id | Assign user to process |
| GET\POST /api/admin/process/unassign | `process_id`-> Required. Process for assign <br> `unassign_user` -> Required. User id | Unassign user of process |
| GET\POST /api//admin/process/remove/{id} | - | Remove process. if process has assigned users - unassign them |
| GET\POST /api/admin/source/get | - | Get list of all sources |
| GET\POST /api/admin/source/add | `name`  -> Required. Unique. Name of new source  <br>`host`  -> Required. Kafka host <br> `topic` -> Required. Kafka topic  <br> `group` -> Name of Kafka Consumer group  <br> | Add new source |
| GET\POST /api/admin/source/delete | `id` -> Required. Id of source to remove | Remove source |
| GET\POST /api/admin/destination/get | - | Get list of all destinations |
| GET\POST /api/admin/destination/add | `name`  -> Required. Unique. Name of new source  <br>`host`  -> Required. Kafka host <br> `topic` -> Required. Kafka topic | Add new destination |
| GET\POST /api/admin/destination/delete | `id` -> Required. Id of destination to remove | Remove destination |
| GET\POST /api/manager/rules/searchExtended | `limit` -> limit rules per page <br> `offset` -> Offcet<br> `sortColumn` -> Int. sort by column (index) <br> `sortDirection` -> (asc\desc) <br>`id` -> [array\int]<br> `query` -> [array\string]<br>  `tags` -> [array\string]<br> `weakTags` -> [array\string]<br> `filters` -> [array\string]<br> `variableName` -> [array\string]<br> | Allow to found rule by tag, query or filters |
| GET\POST /api/manager/rules/replace | `newData`-> Array. New data [`query`, `tags`, `filters`]<br>`limit` -> limit rules per page <br> `offset` -> Offcet<br> `sortColumn` -> Int. sort by column (index) <br> `sortDirection` -> (asc\desc) <br>`id` -> [array\int]<br> `query` -> [array\int]<br>  `tags` -> [array\int]<br> `weakTags` -> [array\int]<br> `filters` -> [array\int]<br> | Allow to update rule by tag, query or filters | 
| GET\POST /api/manager/rules/add |       `rule_id` -> id for new rule. Default 0 <br>  `rule_text` -> Query <br> `rule_filters` -> Filters <br> `rule_tags` -> Tags <br> `rule_external` -> Append to results message if it feature are enabled<br> `rule_highlighting`-> Make highlighting in results message | Add new rule |
| GET\POST /api/manager/rules/import |        `import` Json with fields for import separated by tabs <br> `enable_validation` -> Allow to redeclare rules validation property for current import | Import rules |
| GET\POST /api/manager/rules/delete/{id} | - | Remove rule |
| GET\POST /api/manager/rules/deleteList | `rules_id`-> [array\int] Id to remove | Remove rules |
| GET\POST /api/manager/streams/get | - | Get id of current user's streams |
| GET /api/manager/variables/list |  `streamId` -> [ int ] non required <br> | Return list of Stream variables |
| PUT /api/manager/variables/ | `name` -> [ `string`, `[a-z0-9_]+` ] Required <br> `text` -> [ `string` ] Required  | Create variable |
| GET /api/manager/variables/{name} | - | Get single variable |
| POST /api/manager/variables/{name} | `text` -> [ `string` ] Required  | Edit selected variable |
