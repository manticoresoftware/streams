package manticore;

import manticore.Metrics.PrometheusCollector;
import manticore.Metrics.MetricsStorage;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.Mock;
import org.mockito.MockedStatic;
import org.mockito.junit.jupiter.MockitoExtension;

import java.sql.SQLException;
import java.util.HashMap;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class RuntimeMetricsTest {

    @Mock
    private MetricsStorage mockStorage;

    private RuntimeMetrics runtimeMetrics;

    private static final String LABEL = "test_label";

    @BeforeEach
    void setUp() throws InterruptedException, SQLException {
        // Reset static fields to original values before each test
        RuntimeMetrics.TYPE_ALL_DOCS = "_handler_processed_docs";
        RuntimeMetrics.TYPE_PER_SECOND = "_worker_processed_per_sec";
        RuntimeMetrics.TYPE_RULES = "_handler_processed_rules";
        RuntimeMetrics.TYPE_RULE_COUNT = "_manticore_rules";
        RuntimeMetrics.TYPE_MATCHED_DOCS = "_handler_matched_docs";
        RuntimeMetrics.TYPE_LAG = "_consumer_lag";

        runtimeMetrics = new RuntimeMetrics(LABEL, mockStorage);
    }

    @Test
    void testConstructor() {
        assertEquals(LABEL + "_handler_processed_docs", RuntimeMetrics.TYPE_ALL_DOCS, "TYPE_ALL_DOCS should be prefixed with label");
        assertEquals(LABEL + "_worker_processed_per_sec", RuntimeMetrics.TYPE_PER_SECOND, "TYPE_PER_SECOND should be prefixed with label");
        assertEquals(LABEL + "_handler_processed_rules", RuntimeMetrics.TYPE_RULES, "TYPE_RULES should be prefixed with label");
        assertEquals(LABEL + "_manticore_rules", RuntimeMetrics.TYPE_RULE_COUNT, "TYPE_RULE_COUNT should be prefixed with label");
        assertEquals(LABEL + "_handler_matched_docs", RuntimeMetrics.TYPE_MATCHED_DOCS, "TYPE_MATCHED_DOCS should be prefixed with label");
        assertEquals(LABEL + "_consumer_lag", RuntimeMetrics.TYPE_LAG, "TYPE_LAG should be prefixed with label");
    }

    @Test
    void testSendMetrics() {
        HashMap<String, Number> metrics = new HashMap<>();
        metrics.put("key", 42);

        runtimeMetrics.sendMetrics(RuntimeMetrics.TYPE_RULES, metrics);

        verify(mockStorage).addToBatch(RuntimeMetrics.TYPE_RULES, metrics);
    }

    @Test
    void testSendMetrics_ForLagMetric() {
        HashMap<String, Number> metrics = new HashMap<>();
        metrics.put("", 11);

        runtimeMetrics.sendMetrics(RuntimeMetrics.TYPE_LAG, metrics);

        verify(mockStorage).addToBatch(RuntimeMetrics.TYPE_LAG, metrics);
    }

    @Test
    void testSendProcessed_Success() {
        try (MockedStatic<PrometheusCollector> prometheus = mockStatic(PrometheusCollector.class)) {
            PrometheusCollector collector = mock(PrometheusCollector.class);
            prometheus.when(PrometheusCollector::getInstance).thenReturn(collector);

            runtimeMetrics.setProcessed(10);
            runtimeMetrics.setProcessed(20);

            runtimeMetrics.sendProcessed();

            verify(collector).set(PrometheusCollector.PROCESSED_DOCS, 30);
            HashMap<String, Number> expectedMetrics = new HashMap<>();
            expectedMetrics.put("", 30);
            verify(mockStorage).addToBatch(RuntimeMetrics.TYPE_ALL_DOCS, expectedMetrics);
            assertTrue(runtimeMetrics.processed.isEmpty(), "Processed list should be cleared");
        }
    }

    @Test
    void testSendProcessed_EmptyList() {
        try (MockedStatic<PrometheusCollector> prometheus = mockStatic(PrometheusCollector.class)) {
            PrometheusCollector collector = mock(PrometheusCollector.class);
            prometheus.when(PrometheusCollector::getInstance).thenReturn(collector);

            runtimeMetrics.sendProcessed();

            verify(collector).set(PrometheusCollector.PROCESSED_DOCS, 0);
            HashMap<String, Number> expectedMetrics = new HashMap<>();
            expectedMetrics.put("", 0);
            verify(mockStorage).addToBatch(RuntimeMetrics.TYPE_ALL_DOCS, expectedMetrics);
            assertTrue(runtimeMetrics.processed.isEmpty(), "Processed list should remain empty");
        }
    }

    @Test
    void testSendProcessed_ExceptionHandling() {
        try (MockedStatic<PrometheusCollector> prometheus = mockStatic(PrometheusCollector.class)) {
            PrometheusCollector collector = mock(PrometheusCollector.class);
            prometheus.when(PrometheusCollector::getInstance).thenReturn(collector);
            doThrow(new RuntimeException("Storage failure")).when(mockStorage).addToBatch(anyString(), any(HashMap.class));

            runtimeMetrics.setProcessed(15);

            runtimeMetrics.sendProcessed();

            verify(collector).set(PrometheusCollector.PROCESSED_DOCS, 15);
            assertFalse(runtimeMetrics.processed.isEmpty(), "Processed list should retain data on failure for retry");
            assertEquals(1, runtimeMetrics.processed.size(), "Processed list should still contain one entry");
            assertEquals(15, runtimeMetrics.processed.get(0), "Processed list should retain the original value");
        }
    }

    @Test
    void testSetProcessed() {
        runtimeMetrics.setProcessed(5);
        runtimeMetrics.setProcessed(10);

        assertEquals(2, runtimeMetrics.processed.size(), "Processed list should contain two entries");
        assertTrue(runtimeMetrics.processed.contains(5), "Should contain 5");
        assertTrue(runtimeMetrics.processed.contains(10), "Should contain 10");
    }

    @Test
    void testSendProcessed_AggregatesMultipleProcessedValues() {
        try (MockedStatic<PrometheusCollector> prometheus = mockStatic(PrometheusCollector.class)) {
            PrometheusCollector collector = mock(PrometheusCollector.class);
            prometheus.when(PrometheusCollector::getInstance).thenReturn(collector);

            runtimeMetrics.setProcessed(3);
            runtimeMetrics.setProcessed(4);
            runtimeMetrics.setProcessed(5);

            runtimeMetrics.sendProcessed();

            verify(collector).set(PrometheusCollector.PROCESSED_DOCS, 12);
            HashMap<String, Number> expectedMetrics = new HashMap<>();
            expectedMetrics.put("", 12);
            verify(mockStorage).addToBatch(RuntimeMetrics.TYPE_ALL_DOCS, expectedMetrics);
        }
    }
}
