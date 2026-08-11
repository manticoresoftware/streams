package manticore;

import java.lang.management.ManagementFactory;
import java.util.*;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.ThreadFactory;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;

import manticore.Metrics.PrometheusCollector;
import org.apache.kafka.clients.consumer.ConsumerRecords;
import org.apache.kafka.clients.consumer.OffsetAndMetadata;
import org.apache.kafka.common.TopicPartition;
import ch.qos.logback.classic.Logger;
import manticore.Kafka.Admin;
import manticore.Kafka.Consumer;

public class Worker {
    private String processLabel;
    private String inputTopic;
    private String inputGroupName;
    private final Integer maxBatchSize;
    private final int maxKafkaMessage;
    private final boolean skipExceededMessages;
    private final Long processedMeasureTime;
    private final Integer suspend;
    private Boolean needSendLag = true;
    private final ManualGarbageCleaner manualGarbageCleaner;
    private final ScalingMetrics scalingMetrics;
    protected final ManticoreBatch manticoreBatch;
    private final RuntimeMetrics runtimeMetrics;
    private final Logger logger;
    protected final String instanceLabel;
    private final String processingName;
    protected final Map<TopicPartition, OffsetAndMetadata> currentOffsets = new HashMap<>();
    public boolean isProcessingByTimerRan = false;
    private final ManticoreConnector manticoreConnector;
    private final WorkerConfig config;
    private final Consumer consumer;
    private final Admin admin;
    private final ScheduledExecutorService scheduler;
    private Boolean isFirstOffset = true;
    protected long lastMessageTime = System.currentTimeMillis();

    public Worker(WorkerConfig config, ManticoreConnector manticoreConnector, Consumer consumer, Admin admin,
                  ManticoreBatch manticoreBatch, ManualGarbageCleaner manualGarbageCleaner,
                  ScalingMetrics scalingMetrics, RuntimeMetrics runtimeMetrics, LoggerProvider loggerProvider) {
        this.config = config;
        this.maxBatchSize = config.getMaxBatchSize();
        this.maxKafkaMessage = config.getMaxKafkaMessage();
        this.skipExceededMessages = config.isSkipExceededMessages();
        this.processedMeasureTime = config.getProcessedMeasureTime();
        this.suspend = config.getSuspend();
        this.processingName = config.getProcessingName();
        this.logger = loggerProvider.getLogger();
        this.instanceLabel = String.valueOf(getCurrentTimeMillis());
        this.manticoreConnector = manticoreConnector;
        this.consumer = consumer;
        this.admin = admin;
        this.manticoreBatch = manticoreBatch;
        this.manualGarbageCleaner = manualGarbageCleaner;
        this.scalingMetrics = scalingMetrics;
        this.runtimeMetrics = runtimeMetrics;
        this.scheduler = createScheduler();
    }

    public void init(WorkerConfig config) throws Exception {
        this.processLabel = config.getProcessLabel();
        this.inputTopic = config.getInputTopic();
        this.inputGroupName = config.getInputGroupName();
        initializePrometheusLabels(config.getProcessLabel(), this.inputTopic, this.inputGroupName);

        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            for (int i = 0; i < 600; i++) {
                if (isProcessingByTimerRan) {
                    logger.warn("[Worker] Waiting for query thread to finish for process {}", processLabel);
                    try {
                        Thread.sleep(100);
                    } catch (InterruptedException e) {
                        throw new RuntimeException(e);
                    }
                } else {
                    break;
                }
            }
            shutdownScheduler();
        }));

        if (suspend == 1) {
            logger.info("[Worker] Suspending execution for 1 day for process {}", processLabel);
            Thread.sleep(86400000);
            logger.error("[Worker] Exiting due to suspension timeout for process {}", processLabel);
            fatalExit("Suspended for 1 day", null);
        }

        scheduleTasks();

        int i = 0;
        while (!checkManticoreConnection()) {
            Thread.sleep(1000);
            i++;
            if (i >= 180) {
                logger.error("[Worker] Manticore connection timeout after 180 seconds for process {}", processLabel);
                fatalExit("Manticore connection timeout", null);
            }
        }

        Thread.setDefaultUncaughtExceptionHandler((t, e) -> {
            logger.error("[Worker] Uncaught exception in process {}: {}", processLabel, e.getMessage());
            logger.error("[Worker] Uncaught exception: ", e);
            fatalExit("Uncaught exception", e);
        });

        admin.createTopic(inputTopic);

        runConsumerLoop();
    }

    protected void scheduleTasks() {
        scheduler.scheduleAtFixedRate(() -> runScheduledTask("print usage", this::printUsage),
                0, 5, TimeUnit.SECONDS);
        scheduler.scheduleAtFixedRate(() -> runScheduledTask("send processed metrics", runtimeMetrics::sendProcessed),
                0, processedMeasureTime, TimeUnit.MILLISECONDS);
        scheduler.scheduleAtFixedRate(() -> runScheduledTask("scrap rules count", this::scrapRulesCount),
                0, 60, TimeUnit.SECONDS);
        scheduler.scheduleAtFixedRate(() -> runScheduledTask("mark lag refresh", () -> needSendLag = true),
                0, 5, TimeUnit.SECONDS);
        scheduler.scheduleAtFixedRate(() -> runScheduledTask("update rules count metric", this::updateRulesCountMetric),
                0, 60, TimeUnit.SECONDS);
        scheduler.scheduleAtFixedRate(() -> runScheduledTask("check consumer rebalancing", this::checkConsumerRebalancing),
                0, 5, TimeUnit.SECONDS);
        scheduler.scheduleAtFixedRate(() -> runScheduledTask("process batch by timer", this::processBatchByTimer),
                0, 10, TimeUnit.SECONDS);
        scheduler.scheduleAtFixedRate(() -> runScheduledTask("manual garbage cleaner", manualGarbageCleaner::checkIsNeedRunGC),
                0, 60, TimeUnit.SECONDS);
    }

    private void initializePrometheusLabels(String processLabel, String inputTopic, String inputGroupName) {
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.CONSUMPTION_CPU, instanceLabel, processLabel, inputGroupName);
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.CONSUMPTION_RAM, instanceLabel, processLabel, inputGroupName);
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.KAFKA_OFFSET, inputTopic, inputGroupName);
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.KAFKA_LAG, inputTopic, inputGroupName);
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.BATCH_SIZE, instanceLabel, processLabel, inputGroupName);
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.MATCHED_DOCS, instanceLabel, processLabel, inputGroupName);
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.PROCESSED_DOCS, instanceLabel, processLabel, inputGroupName);
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.SEND_COMMITTED, instanceLabel, processLabel, inputGroupName);
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.RULES_COUNT, instanceLabel, processLabel, inputGroupName, processingName);
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.PROFILE_MAPPING_TIME, instanceLabel, processLabel, inputGroupName);
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.PROFILE_QUERY_TIME, instanceLabel, processLabel, inputGroupName);
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.PROFILE_KAFKA_PRODUCE_TIME, instanceLabel, processLabel, inputGroupName);
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.PROFILE_KAFKA_CONSUME_TIME, instanceLabel, processLabel, inputGroupName);
        PrometheusCollector.getInstance().addLabels(PrometheusCollector.PROFILE_BATCH_HANDLING_TIME, instanceLabel, processLabel, inputGroupName);
    }

    protected void runConsumerLoop() {
        try {
            while (true) {
                pollAndProcess();
            }
        } catch (Exception e) {
            logger.error("[Worker] Consumer loop failed for process {}: {}", processLabel, e.getMessage(), e);
            logOffset();
            fatalExit("Consumer loop failed", e);
        } finally {
            consumer.close();
        }
    }

    protected void fatalExit(String message, Throwable error) {
        logger.error("[Worker] Fatal error for process {}: {}", processLabel, message, error);
        shutdownScheduler();
        exitProcess(1);
        throw new IllegalStateException("Process exit did not terminate JVM", error);
    }

    protected void exitProcess(int status) {
        System.exit(status);
    }

    protected void pollAndProcess() {
        if (needSendLag) {
            getLAG(consumer);
            needSendLag = false;
        }
        Profiler.getInstance().start(Profiler.KAFKA_CONSUME);
        PrometheusCollector.getInstance().startMeasure(PrometheusCollector.PROFILE_KAFKA_CONSUME_TIME);
        ConsumerRecords<String, String> consumerRecords = consumer.consume();
        PrometheusCollector.getInstance().endMeasure(PrometheusCollector.PROFILE_KAFKA_CONSUME_TIME);
        Profiler.getInstance().end(Profiler.KAFKA_CONSUME);
        if (consumerRecords.count() > 0) {
            logger.trace("[Worker] Consumed {} records", consumerRecords.count());
            lastMessageTime = System.currentTimeMillis();

            Integer consumerPoolSize = consumerRecords.count();
            if (manticoreBatch.getSize() < consumerPoolSize) {
                manticoreBatch.setSize(consumerPoolSize);
            }

            consumerRecords.forEach(record -> {
                currentOffsets.put(
                        new TopicPartition(record.topic(), record.partition()),
                        new OffsetAndMetadata(record.offset() + 1, "no metadata"));

                if (isFirstOffset) {
                    logger.debug("[Worker] First offset recorded for process {}", processLabel);
                    logOffset();
                    isFirstOffset = false;
                }

                if (record.serializedValueSize() >= maxKafkaMessage) {
                    String crooppedMessage = record.value().substring(0, 100);
                    logger.warn("[Worker] Kafka message size exceeded for process {}: {} bytes, Message: {}", processLabel, record.serializedValueSize(), crooppedMessage);
                    if (!skipExceededMessages) {
                        logger.error("[Worker] Exiting due to max message size exceeded for process {}, Message: {}", processLabel, crooppedMessage);
                        fatalExit("Max message size exceeded", null);
                    }
                } else {
                    isProcessingByTimerRan = true;
                    if (manticoreBatch.stack(record.value())) {
                        try {
                            consumer.commitSync(currentOffsets);
                            logger.debug("[Worker] Committed offsets asynchronously for process {}", processLabel);
                            logOffset();
                        } catch (Exception e) {
                            logger.warn("[Worker] Failed to commit offsets asynchronously for process {}: {}", processLabel, e.getMessage());
                            logger.trace("[Worker] Failed to commit offsets asynchronously for process {}", processLabel, e);
                        }
                    }
                    isProcessingByTimerRan = false;
                }
            });
        }
    }

    public void logOffset() {
        for (Map.Entry<TopicPartition, OffsetAndMetadata> entry : currentOffsets.entrySet()) {
            logger.debug("[Worker] Offset for partition {}: {} for process {}", entry.getKey().partition(), entry.getValue().offset(), processLabel);
        }
    }

    protected void scrapRulesCount() {
        HashMap<String, Number> rulesCount = new HashMap<>();
        try {
            ManticoreCliResult result = manticoreConnector.executeCli("SELECT count(*) FROM pq");
            if (!result.isEmpty()) {
                rulesCount.put("0", result.firstInt("count(*)"));
            }
        } catch (Exception e) {
            logger.warn("[Worker] Failed to retrieve rules count for process {}: {}", processLabel, e.getMessage());
        }

        logger.debug("[Worker] Sent rules count to metrics storage for process {}", processLabel);
        runtimeMetrics.sendMetrics(RuntimeMetrics.TYPE_RULE_COUNT, rulesCount);
    }

    protected void getLAG(Consumer consumer) {
        // Check for message inactivity
        if (getCurrentTimeForInactivity() - lastMessageTime > (long) config.getInactivityThreshold() * 1000) {
            logger.warn("[Worker] No messages received for >{} seconds, triggering reassignment for process {}", config.getInactivityThreshold(), processLabel);
            consumer.reassign();
            lastMessageTime = getCurrentTimeForInactivity();  // Reset to avoid repeated triggers
        }

        long consumerLAG = 0L;
        long consumerOffset = 0L;

        Set<TopicPartition> partitionSet = consumer.assignment();
        Map<TopicPartition, Long> endOffsets = consumer.endOffsets(consumer.assignment());

        for (TopicPartition tp : partitionSet) {
            long partitionLag = endOffsets.get(tp) - consumer.position(tp);
            consumerOffset += consumer.position(tp);
            consumerLAG += partitionLag;
        }

        try {
            PrometheusCollector.getInstance().set(PrometheusCollector.KAFKA_LAG, consumerLAG);
            PrometheusCollector.getInstance().set(PrometheusCollector.KAFKA_OFFSET, consumerOffset);

            scalingMetrics.setLag(consumerLAG);

            HashMap<String, Number> values = new HashMap<>();
            values.put(instanceLabel, scalingMetrics.getDocsPerSecond());
            runtimeMetrics.sendMetrics(RuntimeMetrics.TYPE_PER_SECOND, values);

            values = new HashMap<>();
            values.put("", consumerLAG);
            runtimeMetrics.sendMetrics(RuntimeMetrics.TYPE_LAG, values);

            logger.debug("[Worker] Sent lag metric {} for process {}", consumerLAG, processLabel);
            scheduler.execute(() -> runScheduledTask("scale batch size", () -> setBatchSize(scalingMetrics.sendMetrics())));
        } catch (Exception e) {
            logger.warn("[Worker] Failed to send lag metric for process {}: {}", processLabel, e.getMessage());
        }
    }

    public void setBatchSize(Integer size) {
        if (size == null) {
            return;
        }
        if (maxBatchSize != null && size > maxBatchSize) {
            size = maxBatchSize;
        }

        PrometheusCollector.getInstance().set(PrometheusCollector.BATCH_SIZE, size);
        scalingMetrics.setBatchSize(size);
        manticoreBatch.setSize(size);
        logger.debug("[Worker] Scaled batch size to {} for process {}", size, processLabel);
    }

    protected boolean checkManticoreConnection() throws InterruptedException {
        try {
            return !manticoreConnector.executeCli("SHOW TABLES like 'pq'").isEmpty();
        } catch (Exception e) {
            logger.warn("[Worker] No Manticore connection available for process {} at host {}", processLabel, manticoreConnector.getManticoreHost());
            return false;
        }
    }

    protected int getManticoreRulesCount() throws InterruptedException {
        try {
            ManticoreCliResult result = manticoreConnector.executeCli("SELECT count(*) as cnt FROM pq");
            if (!result.isEmpty()) {
                return result.firstInt("cnt");
            }
        } catch (Exception e) {
            logger.warn("[Worker] No Manticore connection to retrieve rules count for process {}", processLabel);
            return 0;
        }
        logger.info("[Worker] No rules found in Manticore for process {}", processLabel);
        return 0;
    }

    public static Logger getLogger() {
        return new LoggerProvider().getLogger();
    }

    protected void printUsage() {
        java.lang.management.OperatingSystemMXBean osBean = ManagementFactory.getOperatingSystemMXBean();
        double cpuLoad = 0;
        
        // Use reflection to access process CPU load safely across JDK versions
        try {
            if (osBean instanceof com.sun.management.OperatingSystemMXBean) {
                cpuLoad = ((com.sun.management.OperatingSystemMXBean) osBean).getProcessCpuLoad();
                // If process CPU load is not available, fall back to system CPU load
                if (cpuLoad < 0) {
                    cpuLoad = ((com.sun.management.OperatingSystemMXBean) osBean).getSystemCpuLoad();
                }
            }
        } catch (Exception e) {
            logger.warn("[Worker] Could not retrieve CPU usage for process {}: {}", processLabel, e.getMessage());
            cpuLoad = 0;
        }
        
        // Handle case where CPU load is still not available
        if (cpuLoad < 0) {
            cpuLoad = 0;
        }

        Runtime rt = Runtime.getRuntime();
        PrometheusCollector.getInstance().set(PrometheusCollector.CONSUMPTION_CPU, (long) (cpuLoad * 100));
        PrometheusCollector.getInstance().set(PrometheusCollector.CONSUMPTION_RAM, rt.totalMemory() - rt.freeMemory());
        logger.debug("[Worker] Resource usage for process {}: CPU={}%, RAM={} bytes", processLabel, (long) (cpuLoad * 100), rt.totalMemory() - rt.freeMemory());
    }

    protected long getCurrentTimeMillis() {
        return System.currentTimeMillis();
    }

    protected long getCurrentTimeForInactivity() {
        return getCurrentTimeMillis();
    }

    private ScheduledExecutorService createScheduler() {
        AtomicInteger threadCounter = new AtomicInteger(1);
        ThreadFactory threadFactory = runnable -> {
            Thread thread = new Thread(runnable);
            thread.setName("worker-scheduler-" + threadCounter.getAndIncrement());
            thread.setDaemon(true);
            return thread;
        };
        return Executors.newScheduledThreadPool(8, threadFactory);
    }

    protected void runScheduledTask(String taskName, Runnable task) {
        try {
            task.run();
        } catch (IllegalStateException e) {
            throw e;
        } catch (Exception e) {
            logger.error("[Worker] Scheduled task '{}' failed for process {}: {}", taskName, processLabel, e.getMessage());
            logger.trace("[Worker] Scheduled task failure", e);
        }
    }

    protected void updateRulesCountMetric() {
        try {
            PrometheusCollector.getInstance().set(PrometheusCollector.RULES_COUNT, getManticoreRulesCount());
        } catch (InterruptedException e) {
            logger.error("[Worker] Failed to get Manticore rules count for process {}: {}", processLabel, e.getMessage());
            logger.trace("[Worker] InterruptedException ", e);
        }
    }

    protected void checkConsumerRebalancing() {
        if (consumer.checkIsAtRebalancingState()) {
            logger.error("[Worker] Exiting due to consumer stuck in rebalancing state for process {}", processLabel);
            fatalExit("Consumer stuck on rebalancing", null);
        }
    }

    protected void processBatchByTimer() {
        isProcessingByTimerRan = true;
        try {
            if (manticoreBatch.processByTimer()) {
                try {
                    consumer.commitSync(currentOffsets);
                    logger.info("[Worker] Committed offsets synchronously from timer processing for process {}", processLabel);
                    logOffset();
                } catch (Exception e) {
                    logger.warn("[Worker] Failed to commit offsets synchronously for process {}: {}", processLabel, e.getMessage());
                    logger.trace("[Worker] Failed to commit offsets synchronously", e);
                }
            }
        } finally {
            isProcessingByTimerRan = false;
        }
    }

    protected void shutdownScheduler() {
        scheduler.shutdownNow();
    }
}
