# API

## Access Token
To use the api, you need to get an access token. This can be done through the UI in the Tokens section

![Get token](token.png)

## API reference
The received token must be passed in the header `Authorization: Bearer {token}`

| route | request type | parameters | description |
|---|---|---|---|
| /api/admin/users/get | `GET` `POST` | `start: 0` -> Start offset <br> `length: 50` -> Limit <br> `search[value]:` -> String for searching <br> `search[regex]: false` -> Search by RegEX  | Draw users list | 
| /api/admin/users/add | `GET` `POST` |             `email: 1@1.com` -> User email. Required, must be unique <br> `name: username` -> Username. Required, min 2, unique, regex:`[a-zA-Z0-9]` <br> `token` -> Api Bearer token. Min 32 chars <br> `password` -> Required. Min 5 chars | Add new user |
| /api/admin/users/edit | `GET` `POST` | `user_id` -> Required <br>`role_id` -> Required. admin:1, manager:2 | Switch user between admin and manager |
| /api/admin/users/ban | `GET` `POST` | `user_id` -> Required | Enable &#124; Disable user |
| /api/admin/users/delete | `GET` `POST` | `user_id` -> Required | Force user removing |
| /api/admin/token/reissue | `GET` `POST` | `user_id` -> Required <br>`token`-> Required | Reissue API token |
| /api/admin/process/resolveHosts | `GET` `POST` | `source` -> Required. Id of source record <br> `destination` -> Required. Id of destination record | Return expanded data of hosts | 
| /api/admin/process/parseSchema | `GET` `POST` | `host` -> Required. Kafka host <br>`group`-> Kafka consumer group <br>`topic`-> Topic | Starts job witch get schema from Kafka Topic | 
| /api/admin/process/getSchema | `GET` `POST` | - | Return results of `/api/admin/process/parseSchema` |
| /api/admin/process/extendedInfo/{id} | `GET` `POST` | - | Return extended process settings |
| /api/admin/process/getSuspendList | `GET` `POST` | `process_id` -> Required | Draw list of streams which can be suspended |
| /api/admin/process/getResumeList | `GET` `POST` | `process_id` -> Required | Draw list of streams which can be resumed |
| /api/admin/process/suspend | `GET` `POST` | `streamId` -> Required. Stream id | Suspends selected stream |
| /api/admin/process/resume | `GET` `POST` | `streamId` -> Required. Stream id | Resumes selected stream |
| /api/admin/process/streams/get | `GET` `POST` | `user_id` -> Required. `process_id` -> int | Return data of user streaming |
| /api/admin/process/get | `GET` `POST` | - | Return list of existing processes |
| /api/admin/process/add | `GET` `POST` | `name`-> Required. Name of new process <br>`source_id`->Required. Id of source host<br> `destination_id`->Required. Id of destination host <br> `attrs` -> Required. JSON encoded. Array of fields to transform. Also manticore fields <br> `output_docs`    -> Required. Binary hash (0110). First - return modified names, Second - original names, Third - other names, Four - include info about matching query <br>`max_batch_size` -> Required. Max batch of messages for sending to Manticore <br>`max_threads`    -> Required. Max threads of Manticore <br> `query_complexity_validation` -> Integer (0,1). Enables query validation <br> `language` -> Inploded by coma list of languages <br> `jslt_conf` -> Config for JSLT | Add new process |
| /api/admin/process/assign | `GET` `POST` | `process_id`-> Required. Process for assign <br> `assign_user` -> Required. User id | Assign user to process |
| /api/admin/process/unassign | `GET` `POST` | `process_id`-> Required. Process for assign <br> `unassign_user` -> Required. User id | Unassign user of process |
| /api/admin/process/remove/{id} | `GET` `POST` | - | Remove process. if process has assigned users - unassign them |
| /api/admin/source/get | `GET` `POST` | - | Get list of all sources |
| /api/admin/source/add | `GET` `POST` | `name`  -> Required. Unique. Name of new source  <br>`host`  -> Required. Kafka host <br> `topic` -> Required. Kafka topic  <br> `group` -> Name of Kafka Consumer group  <br> | Add new source |
| /api/admin/source/delete | `GET` `POST` | `id` -> Required. Id of source to remove | Remove source |
| /api/admin/destination/get | `GET` `POST` | - | Get list of all destinations |
| /api/admin/destination/add | `GET` `POST` | `name`  -> Required. Unique. Name of new source  <br>`host`  -> Required. Kafka host <br> `topic` -> Required. Kafka topic | Add new destination |
| /api/admin/destination/delete | `GET` `POST` | `id` -> Required. Id of destination to remove | Remove destination |
| /api/manager/rules/searchExtended | `GET` `POST` | `limit` -> limit rules per page <br> `offset` -> Offset<br> `sortColumn` -> Int. sort by column (index) <br> `sortDirection` -> (asc&#124;desc) <br>`id` -> [ array &#124; int ]<br> `query` -> [ array &#124; string ]<br> `weakQuery` -> [ array &#124; true\false ]<br>  `tags` -> [ array &#124; string ]<br> `weakTags` -> [ array &#124; string ]<br> `filters` -> [ array &#124; string ]<br> `externalQuery` -> [ array &#124; string ]<br> `variableName` -> [array\string]<br> `streamId` -> [ int ] non required <br> | Allow to found rule by tag, query or filters |
| /api/manager/rules/replace | `GET` `POST` | `newData`-> Array. New data [`query`, `tags`, `filters`]<br>`limit` -> limit rules per page <br> `offset` -> Offcet<br> `sortColumn` -> Int. sort by column (index) <br> `sortDirection` -> (asc&#124;desc) <br>`id` -> [ array &#124; int ]<br> `query` -> [ array &#124; int ]<br>  `tags` -> [ array &#124; int ]<br> `weakTags` -> [ array &#124; int ]<br> `filters` -> [ array &#124; int ]<br> `streamId` -> [ int ] non required <br> | Allow to update rule by tag, query or filters | 
| /api/manager/rules/add | `GET` `POST` |      `rule_id` -> id for new rule. Default 0 <br>   `rule_text` -> Query <br> `rule_filters` -> Filters <br> `rule_tags` -> Tags <br> `rule_external` -> Append to results message if it feature are enabled<br> `rule_highlighting`-> Make highlighting in results message <br> `streamId` -> [ int ] non required <br>| Add new rule |
| /api/manager/rules/delete/{id} | `GET` `POST` | `streamId` -> [ int ] non required <br> | Remove rule |
| /api/manager/rules/deleteList | `GET` `POST` | `rules_id`-> [ array &#124; int ] Id to remove <br> `streamId` -> [ int ] non required <br> | Remove rules |
| /api/manager/rules/import | `GET` `POST` | `import`-> json encoded string of import rules `[{"id":"0", "query":"query", "filters":"","tags":"","external":"","highlighting":"true", "duplication_check":"false"}]` <br> `streamId` -> [ int ] non required <br> `enable_validation` -> Allow to redeclare rules validation property for current import <br>   | Import rules in JSON format |
| /api/manager/streams/get | `GET` `POST` | `streamId` -> [ int ] non required <br> | Get id of current user's streams |
| /api/manager/process/get | `GET` `POST` | `streamId` -> [ int ] non required <br> | Get processing parameters of user assigned streams |
| /api/admin/getGraph/ | `GET` `POST` | `actingAs` -> [ int ] required <br> `section` -> [ `matching-docs`, `processed-docs`, `processed-rules`, `processing-lag`]  required | Get graph data for desired section |
| /api/admin/getRuleStatData/{id} | `GET` `POST` | `actingAs` -> [ int ] required | Get graph data for desired rule |
| /api/manager/variables/list | `GET` |  `streamId` -> [ int ] non required <br> | Return list of Stream variables |
| /api/manager/variables/ | `PUT` |  `name` -> [ `string`, `[a-z0-9_]+` ] Required <br> `text` -> [ `string` ] Required  | Create variable |
| /api/manager/variables/{name} | `GET` |    | Get single variable |
| /api/manager/variables/{name} | `POST` | `text` -> [ `string` ] Required  | Edit selected variable |
