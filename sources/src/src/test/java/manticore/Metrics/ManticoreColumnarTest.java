package manticore.Metrics;

import manticore.ManticoreCliResult;
import manticore.Metrics.Batch.MetricsBatch;
import manticore.WorkerConfig;
import org.json.JSONArray;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.ArgumentCaptor;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;

import java.util.Collections;
import java.util.HashMap;
import java.util.List;
import java.lang.reflect.Field;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class ManticoreColumnarTest {

    @Mock
    private MetricsBatch mockBatch;

    private MockedManticoreColumnar columnar;

    @BeforeEach
    void setUp() {
        columnar = spy(new MockedManticoreColumnar());
        columnar.batch = mockBatch;
    }

    @Test
    void testAddToBatch() {
        HashMap<String, Number> metrics = new HashMap<>();
        metrics.put("tag1", 10);
        metrics.put("tag2", 20);

        columnar.addToBatch("metricName", metrics);

        ArgumentCaptor<String> captor = ArgumentCaptor.forClass(String.class);
        verify(mockBatch, times(2)).addToBatch(captor.capture());
        List<String> payloads = captor.getAllValues();

        assertTrue(payloads.get(0).contains("\"insert\""));
        assertTrue(payloads.get(0).contains("\"metric_name\":\"metricName\""));
        assertTrue(payloads.get(0).contains("\"tag\":\"tag1\""));
        assertTrue(payloads.get(1).contains("\"tag\":\"tag2\""));
    }

    @Test
    void testRun_Success() {
        doReturn(1).when(columnar).bulkInsert(anyList());

        columnar.run(List.of("{\"insert\":{}}"));

        verify(columnar).bulkInsert(anyList());
    }

    @Test
    void testRun_EmptyMetrics() {
        columnar.run(Collections.emptyList());

        verify(columnar, never()).bulkInsert(anyList());
        verify(mockBatch, never()).addToBatch(anyString());
    }

    @Test
    void testManticoreColumnarStoresMetricsStorageConfig() {
        WorkerConfig mockConfig = mock(WorkerConfig.class);
        when(mockConfig.getMetricsStorageHost()).thenReturn("metrics-db:19306");
        when(mockConfig.getMetricsStoragePort()).thenReturn(19308);
        when(mockConfig.getManticoreQueryTimeout()).thenReturn(300);

        ManticoreColumnar testColumnar = new MockedManticoreColumnar(mockConfig);

        assertEquals("metrics-db:19306", testColumnar.getHost());
    }

    @Test
    void testCheckTable_CreatesMetricsTableWhenMissing() {
        doReturn(cliResult(), cliResult("status", "ok"), cliResult("Table", "metrics"))
                .when(columnar).executeCli(anyString());

        columnar.invokeRealCheckTable();

        verify(columnar, times(2)).executeCli("SHOW TABLES");
        verify(columnar).executeCli(columnar.getTableCode());
    }

    @Test
    void testCheckTable_LeavesTableUnavailableWhenCreateFails() {
        doReturn(cliResult(), null, cliResult()).when(columnar).executeCli(anyString());

        columnar.invokeRealCheckTable();

        verify(columnar, times(2)).executeCli("SHOW TABLES");
        verify(columnar).executeCli(columnar.getTableCode());
    }

    @Test
    void testExecuteCli_SkipsRequestDuringRetryWindow() throws Exception {
        setField(columnar, "connectionAvailable", false);
        setField(columnar, "lastCheck", System.currentTimeMillis());

        assertNull(columnar.executeCli("SHOW TABLES"));
    }

    private static class MockedManticoreColumnar extends ManticoreColumnar {
        MockedManticoreColumnar() {
            this(createMockConfig());
        }

        MockedManticoreColumnar(WorkerConfig config) {
            super(config);
        }

        @Override
        protected void checkTable() {
        }

        void invokeRealCheckTable() {
            super.checkTable();
        }

        private static WorkerConfig createMockConfig() {
            WorkerConfig mockConfig = mock(WorkerConfig.class);
            when(mockConfig.getMetricsStorageHost()).thenReturn("localhost:19306");
            when(mockConfig.getMetricsStoragePort()).thenReturn(19308);
            when(mockConfig.getManticoreQueryTimeout()).thenReturn(300);
            return mockConfig;
        }
    }

    private ManticoreCliResult cliResult() {
        return cliResult("status", "ok");
    }

    private ManticoreCliResult cliResult(String key, Object value) {
        JSONArray data = new JSONArray().put(new java.util.HashMap<>(java.util.Map.of(key, value)));
        JSONArray resultSets = new JSONArray().put(new java.util.HashMap<>(java.util.Map.of("data", data)));
        return new ManticoreCliResult(resultSets);
    }

    private void setField(Object target, String fieldName, Object value) throws Exception {
        Field field = ManticoreColumnar.class.getDeclaredField(fieldName);
        field.setAccessible(true);
        field.set(target, value);
    }
}
