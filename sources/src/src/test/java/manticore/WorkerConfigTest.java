package manticore;

import ch.qos.logback.classic.Level;
import ch.qos.logback.classic.Logger;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;

import java.util.ArrayList;
import java.util.List;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class WorkerConfigTest {

    @Mock
    private LoggerProvider loggerProvider;

    @Mock
    private Logger logger;

    @Mock
    private Environments environments;

    @BeforeEach
    void setUp() {
        when(loggerProvider.getLogger()).thenReturn(logger);
        when(environments.getLevel(eq("LOG_LEVEL"), any())).thenReturn(Level.INFO);
        when(environments.get(anyString(), anyString())).thenAnswer(invocation -> invocation.getArgument(1));
        when(environments.get(anyString(), anyInt())).thenAnswer(invocation -> invocation.getArgument(1));
        when(environments.get(anyString(), anyLong())).thenAnswer(invocation -> invocation.getArgument(1));
        when(environments.get(anyString(), anyBoolean())).thenAnswer(invocation -> invocation.getArgument(1));
        when(environments.get(anyString(), anyString(), anyList())).thenAnswer(invocation -> invocation.getArgument(2));
    }

    @Test
    void testDefaults() {
        WorkerConfig config = new WorkerConfig(loggerProvider, environments);

        assertEquals("localhost:29092", config.getInputHost());
        assertEquals("localhost:9306", config.getManticoreHost());
        assertEquals(9308, config.getManticoreHttpPort());
        assertEquals("localhost", config.getMetricsStorageHost());
        assertEquals(19308, config.getMetricsStoragePort());
        assertEquals(5000, config.getMaxBatchSize());
        assertEquals(2500, config.getBatchSize());
        assertEquals("1000", config.getOutputDocs());
    }

    @Test
    void testOverridesAndParsesFields() {
        when(environments.get("MANTICORE_HTTP_PORT", 9308)).thenReturn(19408);
        when(environments.get("METRICS_STORAGE_PORT", 19308)).thenReturn(19409);
        when(environments.get("MAX_BATCH_SIZE", 5000)).thenReturn(6000);
        when(environments.get("OUTPUT_DOCS", "1000")).thenReturn("1010");
        when(environments.get("METRICS_STORAGE_HOST", "localhost")).thenReturn("columnar");
        when(environments.get("MANTICORE_FIELDS", "\\|", new ArrayList<>())).thenReturn(List.of("query=query", "tags=tags"));

        WorkerConfig config = new WorkerConfig(loggerProvider, environments);

        assertEquals(19408, config.getManticoreHttpPort());
        assertEquals(19409, config.getMetricsStoragePort());
        assertEquals(6000, config.getMaxBatchSize());
        assertEquals(3000, config.getBatchSize());
        assertEquals("1010", config.getOutputDocs());
        assertEquals("columnar", config.getMetricsStorageHost());
        assertEquals("query", config.getManticoreFields().get("query"));
        assertEquals("tags", config.getManticoreFields().get("tags"));
    }

    @Test
    void testFallsBackForUnsupportedOutputDocs() {
        when(environments.get("OUTPUT_DOCS", "1000")).thenReturn("9999");

        WorkerConfig config = new WorkerConfig(loggerProvider, environments);

        assertEquals("0011", config.getOutputDocs());
        verify(logger).info("[INIT] Output docs non equals .9999");
    }
}
