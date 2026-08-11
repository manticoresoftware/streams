package manticore;

import ch.qos.logback.classic.Logger;
import manticore.Kafka.Admin;
import manticore.Kafka.Consumer;
import manticore.Metrics.PrometheusCollector;
import org.apache.kafka.clients.consumer.ConsumerRecord;
import org.apache.kafka.clients.consumer.ConsumerRecords;
import org.apache.kafka.clients.consumer.OffsetAndMetadata;
import org.apache.kafka.common.TopicPartition;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.Mock;
import org.mockito.MockedStatic;
import org.mockito.junit.jupiter.MockitoExtension;

import java.util.*;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class WorkerTest {

    @Mock private WorkerConfig mockConfig;
    @Mock private ManticoreConnector mockManticoreConnector;
    @Mock private Consumer mockConsumer;
    @Mock private Admin mockAdmin;
    @Mock private ManticoreBatch mockManticoreBatch;
    @Mock private ManualGarbageCleaner mockGarbageCleaner;
    @Mock private ScalingMetrics mockScalingMetrics;
    @Mock private RuntimeMetrics mockRuntimeMetrics;
    @Mock private LoggerProvider mockLoggerProvider;
    @Mock private Logger mockLogger;
    @Mock private PrometheusCollector mockPrometheusCollector;
    @Mock private Profiler mockProfiler;

    private Worker worker;

    @BeforeEach
    void setUp() {
        when(mockLoggerProvider.getLogger()).thenReturn(mockLogger);
        when(mockConfig.getMaxBatchSize()).thenReturn(5000);
        when(mockConfig.getMaxKafkaMessage()).thenReturn(998000);
        when(mockConfig.isSkipExceededMessages()).thenReturn(true);
        when(mockConfig.getProcessedMeasureTime()).thenReturn(10000L);
        when(mockConfig.getSuspend()).thenReturn(0);
        when(mockConfig.getProcessingName()).thenReturn("");

        worker = spy(new Worker(mockConfig, mockManticoreConnector, mockConsumer, mockAdmin,
                mockManticoreBatch, mockGarbageCleaner, mockScalingMetrics, mockRuntimeMetrics, mockLoggerProvider));
    }

    @Test
    void testInit_Success() throws Exception {
        when(mockManticoreConnector.executeCli("SHOW TABLES like 'pq'"))
                .thenReturn(resultRows(new HashMap<>(Map.of("Table", "pq", "Type", "percolate"))));
        doNothing().when(mockAdmin).createTopic(anyString());
        doNothing().when(worker).runConsumerLoop();
        doNothing().when(worker).scheduleTasks();
        when(mockConfig.getProcessLabel()).thenReturn("testLabel");
        when(mockConfig.getInputTopic()).thenReturn("my-docs");
        when(mockConfig.getInputGroupName()).thenReturn("streams-manticore");

        worker.init(mockConfig);

        verify(mockAdmin).createTopic("my-docs");
    }

    @Test
    void testPollAndProcess_EmptyRecords() throws Exception {
        when(mockConsumer.consume()).thenReturn(new ConsumerRecords<>(Collections.emptyMap()));
        TopicPartition partition = new TopicPartition("my-docs", 0);
        when(mockConsumer.assignment()).thenReturn(new HashSet<>(Collections.singletonList(partition)));
        when(mockConsumer.endOffsets(any())).thenReturn(new HashMap<>(Collections.singletonMap(partition, 100L)));
        when(mockConsumer.position(partition)).thenReturn(50L);
        when(mockScalingMetrics.getDocsPerSecond()).thenReturn(0f);

        try (MockedStatic<Profiler> profiler = mockStatic(Profiler.class);
             MockedStatic<PrometheusCollector> prometheus = mockStatic(PrometheusCollector.class)) {
            profiler.when(Profiler::getInstance).thenReturn(mockProfiler);
            prometheus.when(PrometheusCollector::getInstance).thenReturn(mockPrometheusCollector);

            worker.pollAndProcess();

            verify(mockConsumer).consume();
            verifyNoMoreInteractions(mockManticoreBatch);
        }
    }

    @Test
    void testPollAndProcess_WithRecordsCommitsOffsets() throws Exception {
        TopicPartition partition = new TopicPartition("my-docs", 0);
        ConsumerRecord<String, String> record = new ConsumerRecord<>("my-docs", 0, 10L, "key", "value1");
        ConsumerRecords<String, String> consumerRecords =
                new ConsumerRecords<>(Collections.singletonMap(partition, List.of(record)));

        when(mockConsumer.consume()).thenReturn(consumerRecords);
        when(mockConsumer.assignment()).thenReturn(new HashSet<>(Collections.singletonList(partition)));
        when(mockConsumer.endOffsets(any())).thenReturn(new HashMap<>(Collections.singletonMap(partition, 100L)));
        when(mockConsumer.position(partition)).thenReturn(50L);
        when(mockScalingMetrics.getDocsPerSecond()).thenReturn(0f);
        when(mockManticoreBatch.getSize()).thenReturn(0);
        when(mockManticoreBatch.stack("value1")).thenReturn(true);

        try (MockedStatic<Profiler> profiler = mockStatic(Profiler.class);
             MockedStatic<PrometheusCollector> prometheus = mockStatic(PrometheusCollector.class)) {
            profiler.when(Profiler::getInstance).thenReturn(mockProfiler);
            prometheus.when(PrometheusCollector::getInstance).thenReturn(mockPrometheusCollector);

            worker.pollAndProcess();

            verify(mockManticoreBatch).setSize(1);
            verify(mockManticoreBatch).stack("value1");
            verify(mockConsumer).commitSync(anyMap());
            assertEquals(11L, worker.currentOffsets.get(partition).offset());
        }
    }

    @Test
    void testPollAndProcess_SkipsOversizedMessagesWhenConfigured() throws Exception {
        TopicPartition partition = new TopicPartition("my-docs", 0);
        String largeValue = "x".repeat(150);
        @SuppressWarnings("unchecked")
        ConsumerRecord<String, String> record = mock(ConsumerRecord.class);
        when(record.topic()).thenReturn("my-docs");
        when(record.partition()).thenReturn(0);
        when(record.offset()).thenReturn(10L);
        when(record.value()).thenReturn(largeValue);
        when(record.serializedValueSize()).thenReturn(150);
        ConsumerRecords<String, String> consumerRecords =
                new ConsumerRecords<>(Collections.singletonMap(partition, List.of(record)));

        when(mockConfig.getMaxKafkaMessage()).thenReturn(100);
        when(mockConfig.isSkipExceededMessages()).thenReturn(true);
        when(mockConsumer.consume()).thenReturn(consumerRecords);
        when(mockConsumer.assignment()).thenReturn(new HashSet<>(Collections.singletonList(partition)));
        when(mockConsumer.endOffsets(any())).thenReturn(new HashMap<>(Collections.singletonMap(partition, 100L)));
        when(mockConsumer.position(partition)).thenReturn(50L);
        when(mockScalingMetrics.getDocsPerSecond()).thenReturn(0f);

        worker = spy(new Worker(mockConfig, mockManticoreConnector, mockConsumer, mockAdmin,
                mockManticoreBatch, mockGarbageCleaner, mockScalingMetrics, mockRuntimeMetrics, mockLoggerProvider));

        try (MockedStatic<Profiler> profiler = mockStatic(Profiler.class);
             MockedStatic<PrometheusCollector> prometheus = mockStatic(PrometheusCollector.class)) {
            profiler.when(Profiler::getInstance).thenReturn(mockProfiler);
            prometheus.when(PrometheusCollector::getInstance).thenReturn(mockPrometheusCollector);

            worker.pollAndProcess();

            verify(mockManticoreBatch, never()).stack(anyString());
        }
    }

    @Test
    void testCheckManticoreConnection_Success() throws InterruptedException {
        when(mockManticoreConnector.executeCli("SHOW TABLES like 'pq'"))
                .thenReturn(resultRows(new HashMap<>(Map.of("Table", "pq", "Type", "percolate"))));

        assertTrue(worker.checkManticoreConnection());
    }

    @Test
    void testCheckManticoreConnection_NoTable() throws InterruptedException {
        when(mockManticoreConnector.executeCli("SHOW TABLES like 'pq'")).thenReturn(resultRows());

        assertFalse(worker.checkManticoreConnection());
    }

    @Test
    void testScrapRulesCount_Success() throws InterruptedException {
        when(mockManticoreConnector.executeCli("SELECT count(*) FROM pq"))
                .thenReturn(resultRows(new HashMap<>(Map.of("count(*)", 42))));

        worker.scrapRulesCount();

        HashMap<String, Number> expectedMetrics = new HashMap<>();
        expectedMetrics.put("0", 42);
        verify(mockRuntimeMetrics).sendMetrics(RuntimeMetrics.TYPE_RULE_COUNT, expectedMetrics);
    }

    @Test
    void testSetBatchSize_ExceedsMax() {
        try (MockedStatic<PrometheusCollector> prometheus = mockStatic(PrometheusCollector.class)) {
            prometheus.when(PrometheusCollector::getInstance).thenReturn(mockPrometheusCollector);

            worker.setBatchSize(6000);

            verify(mockScalingMetrics).setBatchSize(5000);
            verify(mockManticoreBatch).setSize(5000);
        }
    }

    @Test
    void testGetLag_SendsLagMetrics() {
        TopicPartition partition = new TopicPartition("my-docs", 0);
        when(mockConsumer.assignment()).thenReturn(new HashSet<>(Collections.singletonList(partition)));
        when(mockConsumer.endOffsets(any())).thenReturn(new HashMap<>(Collections.singletonMap(partition, 100L)));
        when(mockConsumer.position(partition)).thenReturn(40L);
        when(mockScalingMetrics.getDocsPerSecond()).thenReturn(5.0f);
        when(mockConfig.getInactivityThreshold()).thenReturn(180);

        try (MockedStatic<PrometheusCollector> prometheus = mockStatic(PrometheusCollector.class)) {
            prometheus.when(PrometheusCollector::getInstance).thenReturn(mockPrometheusCollector);

            worker.getLAG(mockConsumer);

            verify(mockScalingMetrics).setLag(60L);
            verify(mockRuntimeMetrics).sendMetrics(eq(RuntimeMetrics.TYPE_LAG), argThat(map -> map.get("").equals(60L)));
            verify(mockRuntimeMetrics).sendMetrics(eq(RuntimeMetrics.TYPE_PER_SECOND), any(HashMap.class));
        }
    }

    @Test
    void testGetLag_ReassignsConsumerAfterInactivityThreshold() {
        TopicPartition partition = new TopicPartition("my-docs", 0);
        when(mockConsumer.assignment()).thenReturn(new HashSet<>(Collections.singletonList(partition)));
        when(mockConsumer.endOffsets(any())).thenReturn(new HashMap<>(Collections.singletonMap(partition, 100L)));
        when(mockConsumer.position(partition)).thenReturn(100L);
        when(mockScalingMetrics.getDocsPerSecond()).thenReturn(0f);
        when(mockConfig.getInactivityThreshold()).thenReturn(1);
        worker.lastMessageTime = 0L;

        try (MockedStatic<PrometheusCollector> prometheus = mockStatic(PrometheusCollector.class)) {
            prometheus.when(PrometheusCollector::getInstance).thenReturn(mockPrometheusCollector);

            worker.getLAG(mockConsumer);

            verify(mockConsumer).reassign();
        }
    }

    @Test
    void testLogOffset_WithOffsets() {
        TopicPartition partition = new TopicPartition("my-docs", 0);
        OffsetAndMetadata offset = new OffsetAndMetadata(123, "no metadata");
        worker.currentOffsets.put(partition, offset);

        worker.logOffset();

        verify(mockLogger).debug("[Worker] Offset for partition {}: {} for process {}", 0, 123L, null);
    }

    @Test
    void testProcessBatchByTimer_CommitsOffsetsWhenProcessed() {
        TopicPartition partition = new TopicPartition("my-docs", 0);
        worker.currentOffsets.put(partition, new OffsetAndMetadata(123L, "no metadata"));
        when(mockManticoreBatch.processByTimer()).thenReturn(true);

        worker.processBatchByTimer();

        verify(mockConsumer).commitSync(worker.currentOffsets);
        assertFalse(worker.isProcessingByTimerRan);
    }

    @SuppressWarnings("unchecked")
    private ManticoreCliResult resultRows(Map<String, Object>... rows) {
        org.json.JSONArray data = new org.json.JSONArray();
        for (Map<String, Object> row : rows) {
            data.put(row);
        }
        org.json.JSONArray resultSets = new org.json.JSONArray();
        resultSets.put(new HashMap<>(Map.of("data", data)));
        return new ManticoreCliResult(resultSets);
    }
}
