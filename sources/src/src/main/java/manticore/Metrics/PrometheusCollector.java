package manticore.Metrics;

import io.prometheus.client.Counter;
import io.prometheus.client.Gauge;
import io.prometheus.client.Histogram;

import java.util.Arrays;
import java.util.HashMap;
import java.util.Objects;

public class PrometheusCollector {
    public static final String KAFKA_OFFSET = "kafkaOffset";
    public static final String KAFKA_LAG = "kafkaLAG";
    public static final String BATCH_SIZE = "batchSize";
    public static final String MATCHED_DOCS = "matchedDocs";
    public static final String PROCESSED_DOCS = "processedDocs";
    public static final String SEND_COMMITTED = "sendCommitted";
    public static final String RULES_COUNT = "rulesCount";
    public static final String PROFILE_MAPPING_TIME = "mappingTime";
    public static final String PROFILE_QUERY_TIME = "queryTime";
    public static final String PROFILE_KAFKA_PRODUCE_TIME = "kafkaProduce";
    public static final String PROFILE_KAFKA_CONSUME_TIME = "kafkaConsume";
    public static final String PROFILE_BATCH_HANDLING_TIME = "batchHandling";
    public static final String CONSUMPTION_CPU = "consumptionCPU";
    public static final String CONSUMPTION_RAM = "consumptionRAM";

    protected final HashMap<String, String[]> labels = new HashMap<>();
    protected final HashMap<String, Histogram.Timer> timers = new HashMap<>();

    protected final Counter kafkaOffset = Counter.build()
            .name("kafka_offset")
            .help("Kafka Offset")
            .labelNames("topic", "consumer_group").register();

    protected final Gauge kafkaLAG = Gauge.build()
            .name("kafka_lag")
            .help("Kafka LAG")
            .labelNames("topic", "consumer_group").register();

    protected final Gauge batchSize = Gauge.build()
            .name("worker_batch_size")
            .help("Worker batch size")
            .labelNames("instance", "pipeline", "consumer_group")
            .register();

    protected final Histogram matchedDocs = Histogram.build()
            .name("worker_matched_docs_count")
            .help("Worker matched docs count")
            .labelNames("instance", "pipeline", "consumer_group")
            .register();

    protected final Histogram processedDocs = Histogram.build()
            .name("worker_processed_docs_count")
            .help("Worker processed docs count")
            .labelNames("instance", "pipeline", "consumer_group")
            .register();

    protected final Histogram sendCommitted = Histogram.build()
            .name("worker_send_committed")
            .help("Send results count, what been committed")
            .labelNames("instance", "pipeline", "consumer_group")
            .register();

    protected final Gauge rulesCount = Gauge.build()
            .name("worker_rules_count")
            .help("Rules count of current pipeline")
            .labelNames("instance", "pipeline", "consumer_group", "processing_label")
            .register();

    protected final Histogram mappingTime = Histogram.build()
            .name("worker_mapping_time")
            .help("Time spent on mapping")
            .labelNames("instance", "pipeline", "consumer_group")
            .register();

    protected final Histogram queryTime = Histogram.build()
            .name("worker_query_time")
            .help("Time spent on querying")
            .labelNames("instance", "pipeline", "consumer_group")
            .register();

    protected final Histogram produceTime = Histogram.build()
            .name("worker_kafka_produce_time")
            .help("Time spent on producing to Kafka")
            .labelNames("instance", "pipeline", "consumer_group")
            .register();

    protected final Histogram consumeTime = Histogram.build()
            .name("worker_kafka_consume_time")
            .help("Time spent on consuming from Kafka")
            .labelNames("instance", "pipeline", "consumer_group")
            .register();

    protected final Histogram batchHandling = Histogram.build()
            .name("worker_batch_handling_time")
            .help("Time spent on handling batch")
            .labelNames("instance", "pipeline", "consumer_group")
            .register();

    protected final Histogram consumptionCPU = Histogram.build()
            .name("worker_consumption_cpu")
            .help("Worker CPU consumption")
            .labelNames("instance", "pipeline", "consumer_group")
            .register();

    protected final Gauge consumptionRAM = Gauge.build()
            .name("worker_consumption_ram")
            .help("Worker RAM consumption")
            .labelNames("instance", "pipeline", "consumer_group")
            .register();

    private static volatile PrometheusCollector instance;

    private PrometheusCollector() {
    }

    public static PrometheusCollector getInstance() {
        PrometheusCollector result = instance;
        if (result != null) {
            return result;
        }
        synchronized (PrometheusCollector.class) {
            if (instance == null) {
                instance = new PrometheusCollector();
            }
            return instance;
        }
    }

    public void set(String metricName, long value) {

        if (Arrays.asList(new String[]{
                PrometheusCollector.CONSUMPTION_CPU,
                PrometheusCollector.CONSUMPTION_RAM,
                PrometheusCollector.KAFKA_OFFSET,
        }).contains(metricName) && value == 0) {
            return;
        }
        switch (metricName) {
            case PrometheusCollector.KAFKA_OFFSET:
                double currentOffset = kafkaOffset.labels(labels.get(PrometheusCollector.KAFKA_OFFSET)).get();
                double diff = value - currentOffset;
                if (diff < 0){
                    diff = 0;
                }
                kafkaOffset.labels(labels.get(PrometheusCollector.KAFKA_OFFSET)).inc(diff);
                break;
            case PrometheusCollector.KAFKA_LAG:
                kafkaLAG.labels(labels.get(PrometheusCollector.KAFKA_LAG)).set(value);
                break;
            case PrometheusCollector.BATCH_SIZE:
                batchSize.labels(labels.get(PrometheusCollector.BATCH_SIZE)).set(value);
                break;
            case PrometheusCollector.MATCHED_DOCS:
                matchedDocs.labels(labels.get(PrometheusCollector.MATCHED_DOCS)).observe(value);
                break;
            case PrometheusCollector.SEND_COMMITTED:
                sendCommitted.labels(labels.get(PrometheusCollector.SEND_COMMITTED)).observe(value);
                break;
            case PrometheusCollector.RULES_COUNT:
                rulesCount.labels(labels.get(PrometheusCollector.RULES_COUNT)).set(value);
                break;
            case PrometheusCollector.PROCESSED_DOCS:
                processedDocs.labels(labels.get(PrometheusCollector.PROCESSED_DOCS)).observe(value);
                break;
            case PrometheusCollector.CONSUMPTION_CPU:
                consumptionCPU.labels(labels.get(PrometheusCollector.CONSUMPTION_CPU)).observe(value);
                break;
            case PrometheusCollector.CONSUMPTION_RAM:
                consumptionRAM.labels(labels.get(PrometheusCollector.CONSUMPTION_RAM)).set(value);
                break;
        }
    }

    public void addLabels(String metricName, String... labels) {
        this.labels.put(metricName, labels);
    }

    public void startMeasure(String metricName) {
        Histogram.Timer timer = null;
        switch (metricName) {
            case PrometheusCollector.PROFILE_MAPPING_TIME:
                timer = mappingTime.labels(labels.get(PrometheusCollector.PROFILE_MAPPING_TIME)).startTimer();
                break;
            case PrometheusCollector.PROFILE_QUERY_TIME:
                timer = queryTime.labels(labels.get(PrometheusCollector.PROFILE_QUERY_TIME)).startTimer();
                break;
            case PrometheusCollector.PROFILE_KAFKA_PRODUCE_TIME:
                timer = produceTime.labels(labels.get(PrometheusCollector.PROFILE_KAFKA_PRODUCE_TIME)).startTimer();
                break;
            case PrometheusCollector.PROFILE_KAFKA_CONSUME_TIME:
                timer = consumeTime.labels(labels.get(PrometheusCollector.PROFILE_KAFKA_CONSUME_TIME)).startTimer();
                break;
            case PrometheusCollector.PROFILE_BATCH_HANDLING_TIME:
                timer = batchHandling.labels(labels.get(PrometheusCollector.PROFILE_BATCH_HANDLING_TIME)).startTimer();
                break;
        }
        if (timer != null) {
            timers.put(metricName, timer);
        }
    }

    public void endMeasure(String metricName) {
        String[] allowedNames = {
                PrometheusCollector.PROFILE_MAPPING_TIME,
                PrometheusCollector.PROFILE_QUERY_TIME,
                PrometheusCollector.PROFILE_KAFKA_PRODUCE_TIME,
                PrometheusCollector.PROFILE_KAFKA_CONSUME_TIME,
                PrometheusCollector.PROFILE_BATCH_HANDLING_TIME
        };
        if (Arrays.asList(allowedNames).contains(metricName)) {
            Histogram.Timer timer = timers.get(metricName);
            if (timer != null) {
                timer.observeDuration();
            }
        }
    }
}