package manticore;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

public class WorkerConfig {
    private static final String[] SUPPORTED_OUTPUT_DOCS = {"1000", "0100", "0010", "1010", "1001", "0101", "0011", "1011"};

    private String processLabel = "";
    private String inputHost = "localhost:29092";
    private String scalerHost = "localhost:8808";
    private String inputTopic = "my-docs";
    private String inputGroupName = "streams-manticore";
    private String outputHost = "localhost:29092";
    private String outputTopic = "my-results";
    private String manticoreHost = "localhost:9306";
    private int manticoreHttpPort = 9308;
    private int maxBatchSize = 5000;
    private int minThreads = 1;
    private int maxThreads = 3;
    private String metricsStorageHost = "localhost";
    private int metricsStoragePort = 19308;
    private String outputDocs = "1001";
    private int manticoreQueryTimeout = 300;
    private int maxKafkaMessage = 998000;
    private boolean skipExceededMessages = true;
    private String jsltConfig = "";
    private long processedMeasureTime = 10000L;
    private int batchSize = 1;
    private int suspend = 0;
    private String processingName = "";
    private List<String> rules = new ArrayList<>();
    private final Map<String, String> manticoreFields = new HashMap<>();
    private final LoggerProvider loggerProvider;
    private final Environments environments;

    private int kafkaFetchMinBytes = 1;
    private int kafkaFetchMaxWaitMs = 500;
    private int kafkaFetchMaxBytes = 1048576;
    private int kafkaMaxPollRecords = 500;
    private int inactivityThreshold = 180;

    public WorkerConfig(LoggerProvider loggerProvider) {
        this(loggerProvider, new Environments());
    }

    WorkerConfig(LoggerProvider loggerProvider, Environments environments) {
        this.loggerProvider = loggerProvider;
        this.environments = environments;
        readEnvironmentValues();
    }

    private void readEnvironmentValues() {
        processLabel = environments.get("LABEL", processLabel);
        inputHost = environments.get("KAFKA_INPUT_HOST", inputHost);
        outputHost = environments.get("KAFKA_OUTPUT_HOST", outputHost);
        inputTopic = environments.get("KAFKA_INPUT_TOPIC", inputTopic);
        outputTopic = environments.get("KAFKA_OUTPUT_TOPIC", outputTopic);
        inputGroupName = environments.get("KAFKA_GROUP_NAME", inputGroupName);
        manticoreHost = environments.get("MANTICORE_HOST", manticoreHost);
        manticoreHttpPort = environments.get("MANTICORE_HTTP_PORT", manticoreHttpPort);
        minThreads = environments.get("MIN_THREADS", minThreads);
        maxThreads = environments.get("MAX_THREADS", maxThreads);
        processedMeasureTime = environments.get("PROCESSED_MEASURE_TIME", processedMeasureTime);
        outputDocs = environments.get("OUTPUT_DOCS", "1000");
        metricsStorageHost = environments.get("METRICS_STORAGE_HOST", metricsStorageHost);
        metricsStoragePort = environments.get("METRICS_STORAGE_PORT", metricsStoragePort);
        scalerHost = environments.get("SCALER_HOST", scalerHost);
        maxBatchSize = environments.get("MAX_BATCH_SIZE", maxBatchSize);
        jsltConfig = environments.get("JSLT_CONFIG", jsltConfig);
        suspend = environments.get("SUSPEND", suspend);
        maxKafkaMessage = environments.get("MAX_MESSAGE_SIZE", maxKafkaMessage);
        skipExceededMessages = environments.get("SKIP_EXCEEDED_MESSAGES", skipExceededMessages);
        manticoreQueryTimeout = environments.get("MAX_MANTICORE_QUERY_TIMEOUT", manticoreQueryTimeout);
        processingName = environments.get("PROCESSING_NAME", processingName);
        ch.qos.logback.classic.Level logLevel = environments.getLevel("LOG_LEVEL", ch.qos.logback.classic.Level.INFO);

        loggerProvider.getLogger().setLevel(logLevel);

        List<String> fields = environments.get("MANTICORE_FIELDS", "\\|", new ArrayList<>());
        for (String field : fields) {
            String[] splitted = field.split("=");
            manticoreFields.put(splitted[1], splitted[0]);
        }

        batchSize = maxBatchSize / 2;

        if (!Arrays.asList(SUPPORTED_OUTPUT_DOCS).contains(outputDocs)) {
            loggerProvider.getLogger().info("[INIT] Output docs non equals ." + outputDocs);
            outputDocs = "0011";
        }

        rules = environments.get("TRANSFORM_RULES", "\\|", new ArrayList<>());

        kafkaFetchMinBytes = environments.get("KAFKA_FETCH_MIN_BYTES", kafkaFetchMinBytes);
        kafkaFetchMaxWaitMs = environments.get("KAFKA_FETCH_MAX_WAIT_MS", kafkaFetchMaxWaitMs);
        kafkaFetchMaxBytes = environments.get("KAFKA_FETCH_MAX_BYTES", kafkaFetchMaxBytes);
        kafkaMaxPollRecords = environments.get("KAFKA_MAX_POLL_RECORDS", kafkaMaxPollRecords);
        inactivityThreshold = environments.get("INACTIVITY_THRESHOLD", inactivityThreshold);

        logInitValue("Label set as ", processLabel);
        if (suspend == 1) {
            loggerProvider.getLogger().error("[INIT] Suspend " + suspend);
        } else {
            logInitValue("Suspend ", suspend);
        }
        logInitValue("Kafka host set as ", inputHost);
        logInitValue("Kafka output host set as ", outputHost);
        logInitValue("Input topic set as ", inputTopic);
        logInitValue("Output topic set as ", outputTopic);
        logInitValue("Group name set as ", inputGroupName);
        logInitValue("Manticore host set as ", manticoreHost);
        logInitValue("Manticore HTTP port set as ", manticoreHttpPort);
        logInitValue("Measure time set as ", processedMeasureTime);
        logInitValue("Metrics storage host was set to ", metricsStorageHost);
        logInitValue("Metrics storage port was set to ", metricsStoragePort);
        logInitValue("Output docs set to ", outputDocs);
        logInitValue("Max request execution time set to ", manticoreQueryTimeout);
        logInitValue("Processing label set to ", processingName);
        logInitValue("Kafka fetch.min.bytes set to ", kafkaFetchMinBytes);
        logInitValue("Kafka fetch.max.wait.ms set to ", kafkaFetchMaxWaitMs);
        logInitValue("Kafka fetch.max.bytes set to ", kafkaFetchMaxBytes);
        logInitValue("Kafka max.poll.records set to ", kafkaMaxPollRecords);
        logInitValue("Inactivity threshold set to ", inactivityThreshold + " seconds");
    }

    private void logInitValue(String message, Object value) {
        loggerProvider.getLogger().info("[INIT] " + message + value);
    }

    public String getProcessLabel() { return processLabel; }
    public String getInputHost() { return inputHost; }
    public String getScalerHost() { return scalerHost; }
    public String getInputTopic() { return inputTopic; }
    public String getInputGroupName() { return inputGroupName; }
    public String getOutputHost() { return outputHost; }
    public String getOutputTopic() { return outputTopic; }
    public String getManticoreHost() { return manticoreHost; }
    public int getManticoreHttpPort() { return manticoreHttpPort; }
    public int getMaxBatchSize() { return maxBatchSize; }
    public int getMinThreads() { return minThreads; }
    public int getMaxThreads() { return maxThreads; }
    public String getMetricsStorageHost() { return metricsStorageHost; }
    public int getMetricsStoragePort() { return metricsStoragePort; }
    public String getOutputDocs() { return outputDocs; }
    public int getManticoreQueryTimeout() { return manticoreQueryTimeout; }
    public int getMaxKafkaMessage() { return maxKafkaMessage; }
    public boolean isSkipExceededMessages() { return skipExceededMessages; }
    public String getJsltConfig() { return jsltConfig; }
    public long getProcessedMeasureTime() { return processedMeasureTime; }
    public int getBatchSize() { return batchSize; }
    public int getSuspend() { return suspend; }
    public String getProcessingName() { return processingName; }
    public List<String> getRules() { return rules; }
    public Map<String, String> getManticoreFields() { return manticoreFields; }
    public int getKafkaFetchMinBytes() { return kafkaFetchMinBytes; }
    public int getKafkaFetchMaxWaitMs() { return kafkaFetchMaxWaitMs; }
    public int getKafkaFetchMaxBytes() { return kafkaFetchMaxBytes; }
    public int getKafkaMaxPollRecords() { return kafkaMaxPollRecords; }
    public int getInactivityThreshold() { return inactivityThreshold; }
}
