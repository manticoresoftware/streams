# Prometheus metrics

Manticore Streams allows Prometheus to scrape its metrics out of the box. If your Prometheus server has a default configuration, you don't need to do anything for that.

### Kafka metrics
* `kafka_offset` (topic, consumer_group) - Kafka offset for the selected topic and consumer group.
* `kafka_lag` (topic, consumer_group) - Kafka lag for the selected topic and consumer group.

### Processing metrics
* `worker_batch_size` (instance, pipeline) - shows current documents processing batch size.
* `worker_matched_docs_count` (instance, pipeline) - shows matched documents rate.
* `worker_processed_docs_count` (instance, pipeline) - shows worker's input rate (how many documents were processed).

### Worker profile metrics. 
This metrics can help you to catch bad releases of Worker and allow to quick handle those issues.
* `worker_mapping_time` (instance, pipeline) - Time which worker spent on documents transformation (mapping).
* `worker_query_time` (instance, pipeline) - Time spent on Call PQ directly.
* `worker_kafka_produce_time` (instance, pipeline) - Producing in Kafka time. 
* `worker_kafka_consume_time` (instance, pipeline) - Consuming in Kafka time.
* `worker_batch_handling_time` (instance, pipeline) - Time spent at full batch handling. Maybe useful with worker_batch_size metric.
