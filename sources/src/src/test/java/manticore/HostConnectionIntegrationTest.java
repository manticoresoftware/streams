package manticore;

import manticore.Metrics.ManticoreColumnar;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class HostConnectionIntegrationTest {

    @Mock
    private WorkerConfig mockConfig;

    @Test
    void testBatchAndStorageUseDifferentHosts() {
        when(mockConfig.getManticoreHost()).thenReturn("localhost:9306");
        when(mockConfig.getManticoreHttpPort()).thenReturn(9308);
        when(mockConfig.getMetricsStorageHost()).thenReturn("metrics-db:19306");
        when(mockConfig.getMetricsStoragePort()).thenReturn(19308);
        when(mockConfig.getManticoreQueryTimeout()).thenReturn(300);

        ManticoreConnector batchConnector = new ManticoreConnector(
                mockConfig.getManticoreHost(), mockConfig.getManticoreHttpPort(), mockConfig.getManticoreQueryTimeout());

        ManticoreColumnar storage = new ManticoreColumnar(mockConfig) {
            @Override
            protected void checkTable() {
            }
        };

        assertEquals("localhost:9306", batchConnector.getManticoreHost());
        assertEquals("metrics-db:19306", storage.getHost());
        assertNotEquals(batchConnector.getManticoreHost(), storage.getHost());
    }

    @Test
    void testConnectorStoresDifferentHosts() {
        ManticoreConnector connector1 = new ManticoreConnector("localhost:9306", 9308, 300);
        ManticoreConnector connector2 = new ManticoreConnector("metrics:19306", 19308, 300);

        assertEquals("localhost:9306", connector1.getManticoreHost());
        assertEquals("metrics:19306", connector2.getManticoreHost());
        assertEquals(300, connector1.getQueryTimeout());
        assertNotEquals(connector1.getManticoreHost(), connector2.getManticoreHost());
    }
}
