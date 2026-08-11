package manticore.Metrics;

import io.prometheus.client.CollectorRegistry;
import io.prometheus.client.Histogram;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.junit.jupiter.MockitoExtension;

import static org.junit.jupiter.api.Assertions.*;

@ExtendWith(MockitoExtension.class)
public class PrometheusCollectorTest {

    private PrometheusCollector collector;

    @BeforeEach
    void setUp() {
        CollectorRegistry.defaultRegistry.clear();
        collector = PrometheusCollector.getInstance();
        collector.timers.clear();  // Clear timers to prevent residual state
        collector.labels.clear();  // Clear labels to prevent NPEs
    }

    @Test
    void testSingletonBehavior() {
        PrometheusCollector anotherInstance = PrometheusCollector.getInstance();
        assertSame(collector, anotherInstance, "Both instances should be the same");
    }

    @Test
    void testAddLabels() {
        collector.addLabels(PrometheusCollector.KAFKA_OFFSET, "topic1", "group1");
        String[] labels = collector.labels.get(PrometheusCollector.KAFKA_OFFSET);
        assertArrayEquals(new String[]{"topic1", "group1"}, labels);
    }

    @Test
    void testSetMetrics() {
        collector.addLabels(PrometheusCollector.KAFKA_OFFSET, "topic1", "group1");
        collector.set(PrometheusCollector.KAFKA_OFFSET, 100);

        double value = collector.kafkaOffset.labels(
                collector.labels.get(PrometheusCollector.KAFKA_OFFSET)
        ).get();

        assertEquals(100.0, value, 0.001, "Metric value should match set value");
    }

    @Test
    void testStartAndEndMeasure() {
        collector.addLabels(PrometheusCollector.PROFILE_QUERY_TIME, "instance1", "pipeline1", "group1");
        collector.startMeasure(PrometheusCollector.PROFILE_QUERY_TIME);

        assertNotNull(collector.timers.get(PrometheusCollector.PROFILE_QUERY_TIME), "Timer should be initialized");

        collector.endMeasure(PrometheusCollector.PROFILE_QUERY_TIME);

        // Timer should remain in the map after ending, but its observation is complete
        Histogram.Timer timer = collector.timers.get(PrometheusCollector.PROFILE_QUERY_TIME);
        assertNotNull(timer, "Timer should still exist after ending");
    }

    @Test
    void testSetMetricIgnoreZero() {
        collector.addLabels(PrometheusCollector.KAFKA_OFFSET, "topic1", "group1");
        collector.set(PrometheusCollector.KAFKA_OFFSET, 0);

        Double value = CollectorRegistry.defaultRegistry.getSampleValue(
                "kafka_offset",
                new String[]{"topic", "consumer_group"},
                new String[]{"topic1", "group1"}
        );
        assertNull(value, "Zero value should not be registered");
    }

    @Test
    void testEndMeasureWithoutStart() {
        collector.endMeasure(PrometheusCollector.PROFILE_QUERY_TIME);
        assertNull(collector.timers.get(PrometheusCollector.PROFILE_QUERY_TIME),
                "Timer should not exist if measure wasn’t started");
    }
}