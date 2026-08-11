# Stream

The stream is created when we assign user to process.

Below described main parameters and principles of streaming.

## Modifying of output documents

This feature allows modifying output stream through selection of options:

<!-- example output-docs -->

* No input JSON transformation - _Leave result as is_
* Leave only JSON nodes you will approve on the next step with their original names
* Leave only JSON nodes you will approve on the next step with their new names
* Use new names for the JSON nodes you will approve on the next step
* Include node about matching queries into each output document - _Add the node with information about matched rule_

<!-- intro -->

### Leave only JSON nodes you will approve on the next step with their original names

<!-- request Leave only JSON nodes you will approve on the next step with their original names -->

Rule: `data.query -> query`

Input message example:

```
{
   	"message": "some text",
   	"data": {
   		"query": "some query"
   	},
   	"user": {
   		"status": "some status"
   	}
}
```

<!-- response Leave only JSON nodes you will approve on the next step with their original names -->

```
{"data":{"query":"some query"}}
```

<!-- intro -->

### Leave only JSON nodes you will approve on the next step with their new names

<!-- request Leave only JSON nodes you will approve on the next step with their new names -->

Rule: `data.query -> query`

Input message example:

```
{
	"message": "some text",
	"data": {
		"query": "some query"
	},
	"user": {
		"status": "some status"
	}
}
```

<!-- response Leave only JSON nodes you will approve on the next step with their new names -->

```
{"query":"some query"}
```

<!-- intro -->

### Use new names for the JSON nodes you will approve on the next step

<!-- request Use new names for the JSON nodes you will approve on the next step -->

Rule: `data.query -> query`

Input message example:

```
{
	"message": "some text",
	"data": {
		"query": "some query"
	},
	"user": {
		"status": "some status"
	}
}
```

<!-- response Use new names for the JSON nodes you will approve on the next step -->

```
{
	"message": "some text",
	"query": "some query",
	"user": {
		"status": "some status"
	}
}
```

<!-- end -->

# Scaling

The worker set batch size based on current processing measures.

So if we have a large LAG value and the current value of batch size not at maximum - the scaler will increase batch
size.

If now we have a high value of current documents processing and low LAG - the scaler will decrease batch.

In case when we have already a max batch size, but the current thread count has not grown to the max value, the scaler
will add a new Manticore instance

# Query complexity validation

This feature allows checking how correct rule user will add. If the rule has many matches - this is the bad rule.

If this parameter was enabled, we create additional microservice when a new stream is deployed. This microservice
receives and store a small part of the data from the topic, updating this data once an hour. After when a new rule is
added, it goes through all transformations and generates the final query for verifying.

Thus, we can assess how valid the rule is and how many documents were mathing gy this rule.

## Data types of Mapped fields

Data type that we detect and subsequently use for Manticore fields

* String
* Integer
* Big integer
* Float
* Boolean
* Timestamp
* JSON - [JSON filtering](ReadyToWork/Streams.md#JSON filtering)
* URL - [search by URL](ReadyToWork/Streams.md#URL filtering)

## Field merging ![Merge icon](Admin/MergeIcon.png)

Allow to merge the data of several nodes into one key

`comment.author.name`
`comment.lang` -> `merged_field`

## Rules transformation

Rules transformation Allow you to set the keys by which the search will be conducted, discarding all unnecessary.

For example, we want to get all documents in which `data.query` contains the text `dolor sit`, and the `lang` key acts
as a filter with the value `"latin"`

We create a rule for Manticore

* Query: `@query query`
* filter: `json.lang == "latin"`

Transformation rule

```
data.query => query
whole_document => json
```

Result will be a match of this message:

```
{
    "message": "some text",
    "lang": "latin",
    "data": {
        "query": "Lorem ipsum dolor sit amet, consectetur adipiscing elit"
    },
    "user": {
        "status": "some status"
    }    
}
```

## JSON filtering

<!-- example json-filtering -->
Allow filtering messages through JSON key
<!-- intro -->

<!-- request JSON filtering -->


Transform Rule: `whole_document -> json`

Manticore filter: `json.lang = "en"`

Input messages example:

```
{
	"message": "some text",
    "lang": "en",
	"data": {
		"query": "some query"
	},
	"user": {
		"status": "some status"
	}
}

{
	"message": "какой то текст",
    "lang": "ru",
	"data": {
		"query": "какой то запрос"
	},
	"user": {
		"status": "какой то статус"
	}
}
```

<!-- response JSON filtering -->

```
{
	"message": "some text",
    "lang": "en",
	"data": {
		"query": "some query"
	},
	"user": {
		"status": "some status"
	}
}
```

<!-- end -->

## URL filtering

<!-- example url-filtering -->
**Attention!** URL filter **is specified in query**, not in filter !!!

Allows you to filter the request by URL. It can be a separate key (including the merged format) or part of a message.

Operators OR (|) and stopwords words are supported.

<!-- intro -->

<!-- request one field filtering -->

```
@myurl manticoresearch.com | https://mail.google.com/ -https://fb.com
```

<!-- request many fields filtering -->

```
@(merged, merged2) https://mail.google.com/ -manticoresearch.com
```

<!-- end -->

## Metrics Retention

In case you want not to save expired rows, we allow you to specify TTL value for metrics saved in Columnar. This
retention affects processing, rules, and LAG metrics. Just change `columnar.ttl` value in `values.yaml`. TTL value must
be set in days. Default retention value is 30 days
