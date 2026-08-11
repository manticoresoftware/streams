package manticore;

import kong.unirest.HttpResponse;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;

import java.util.concurrent.ExecutionException;
import java.util.concurrent.Future;
import java.util.concurrent.TimeoutException;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class ManticoreConnectorTest {

    @Mock
    private HttpResponse<String> response;

    private TestableManticoreConnector connector;

    private static final String MANTICORE_HOST = "localhost:9306";
    private static final int HTTP_PORT = 9308;
    private static final int QUERY_TIMEOUT = 300;

    @BeforeEach
    void setUp() {
        connector = spy(new TestableManticoreConnector(MANTICORE_HOST, HTTP_PORT, QUERY_TIMEOUT));
    }

    @Test
    void testConstructor() {
        assertEquals(MANTICORE_HOST, connector.getManticoreHost());
        assertEquals(QUERY_TIMEOUT, connector.getQueryTimeout());
    }

    @Test
    void testExecuteCli_SuccessFirstAttempt() throws Exception {
        when(response.getStatus()).thenReturn(200);
        when(response.getBody()).thenReturn("[{\"data\":[{\"cnt\":1}],\"error\":\"\"}]");
        doReturn(response).when(connector).postCli("SELECT 1");

        ManticoreCliResult result = connector.executeCli("SELECT 1");

        assertEquals(1, result.rows().size());
        assertEquals(1, result.firstRow().optInt("cnt"));
        verify(connector, never()).sleep(anyLong());
    }

    @Test
    void testExecuteCli_RetrySuccessAfterOneFailure() throws Exception {
        when(response.getStatus()).thenReturn(200);
        when(response.getBody()).thenReturn("[{\"data\":[],\"error\":\"\"}]");
        doThrow(new RuntimeException("First failure"))
                .doReturn(response)
                .when(connector).postCli("SHOW TABLES");

        ManticoreCliResult result = connector.executeCli("SHOW TABLES");

        assertNotNull(result);
        assertTrue(result.isEmpty());
        verify(connector).sleep(1L);
        verify(connector, times(2)).postCli("SHOW TABLES");
    }

    @Test
    void testExecuteCli_FailureAfterMaxAttempts() throws Exception {
        doThrow(new RuntimeException("Repeated failure")).when(connector).postCli("SHOW TABLES");

        RuntimeException exception = assertThrows(RuntimeException.class, () -> connector.executeCli("SHOW TABLES"));

        assertEquals("Repeated failure", exception.getMessage());
        verify(connector, times(2)).sleep(1L);
        verify(connector, times(3)).postCli("SHOW TABLES");
    }

    @Test
    void testBuildCliEndpoint() {
        assertEquals("http://localhost:19308/cli_json",
                ManticoreConnector.buildCliEndpoint("localhost:19306", 19308));
    }

    @Test
    void testBuildBulkEndpoint() {
        assertEquals("http://metrics-storage:9308/bulk",
                ManticoreConnector.buildBulkEndpoint("metrics-storage:9306", 9308));
    }

    @Test
    void testParseCliResponse_WrapsSingleObject() {
        when(response.getStatus()).thenReturn(200);
        when(response.getBody()).thenReturn("{\"data\":[{\"cnt\":2}],\"error\":\"\"}");

        ManticoreCliResult result = ManticoreConnector.parseCliResponse(response);

        assertEquals(1, result.rows().size());
        assertEquals(2, result.firstRow().optInt("cnt"));
    }

    @Test
    void testParseCliResponse_ThrowsWhenResultContainsError() {
        when(response.getStatus()).thenReturn(200);
        when(response.getBody()).thenReturn("[{\"data\":[],\"error\":\"broken query\"}]");

        RuntimeException exception = assertThrows(RuntimeException.class, () -> ManticoreConnector.parseCliResponse(response));

        assertEquals("broken query", exception.getMessage());
    }

    @Test
    void testParseCliResponse_EmptyBodyReturnsEmptyResult() {
        when(response.getStatus()).thenReturn(200);
        when(response.getBody()).thenReturn("");

        ManticoreCliResult result = ManticoreConnector.parseCliResponse(response);

        assertTrue(result.isEmpty());
    }

    @Test
    void testExecuteBulk_RetrySuccessAfterOneFailure() throws Exception {
        when(response.getStatus()).thenReturn(200);
        when(response.getBody()).thenReturn("{\"errors\":false}");
        doThrow(new RuntimeException("bulk failed"))
                .doReturn(response)
                .when(connector).postBulk("payload");

        connector.executeBulk("payload");

        verify(connector).sleep(1L);
        verify(connector, times(2)).postBulk("payload");
    }

    @Test
    void testExecuteBulk_ThrowsWhenResponseHasErrors() throws Exception {
        when(response.getStatus()).thenReturn(200);
        when(response.getBody()).thenReturn("{\"errors\":true}");
        doReturn(response).when(connector).postBulk("payload");

        RuntimeException exception = assertThrows(RuntimeException.class, () -> connector.executeBulk("payload"));

        assertTrue(exception.getMessage().contains("\"errors\":true"));
        verify(connector, times(2)).sleep(1L);
        verify(connector, times(3)).postBulk("payload");
    }

    @Test
    void testExecuteBulk_ThrowsWhenHttpStatusIsError() throws Exception {
        when(response.getStatus()).thenReturn(500);
        when(response.getBody()).thenReturn("server error");
        doReturn(response).when(connector).postBulk("payload");

        RuntimeException exception = assertThrows(RuntimeException.class, () -> connector.executeBulk("payload"));

        assertEquals("server error", exception.getMessage());
        verify(connector, times(3)).postBulk("payload");
    }

    @Test
    void testPost_ThrowsTimeoutError() throws Exception {
        Future<HttpResponse<String>> request = mock(Future.class);
        TestableManticoreConnector connector = spy(new TestableManticoreConnector(MANTICORE_HOST, HTTP_PORT, 5));
        doReturn(request).when(connector).startRequest("http://localhost:9308/cli_json", "text/plain", "SELECT 1");
        doThrow(new TimeoutException("timeout")).when(connector).awaitResponse(request);

        RuntimeException exception = assertThrows(RuntimeException.class,
                () -> connector.post("http://localhost:9308/cli_json", "text/plain", "SELECT 1"));

        assertEquals("Manticore HTTP query timed out after 5 seconds", exception.getMessage());
        verify(request).cancel(true);
    }

    @Test
    void testPost_UnwrapsExecutionExceptionCause() throws Exception {
        Future<HttpResponse<String>> request = mock(Future.class);
        TestableManticoreConnector connector = spy(new TestableManticoreConnector(MANTICORE_HOST, HTTP_PORT, 5));
        doReturn(request).when(connector).startRequest("http://localhost:9308/cli_json", "text/plain", "SELECT 1");
        doThrow(new ExecutionException(new IllegalStateException("root cause"))).when(connector).awaitResponse(request);

        RuntimeException exception = assertThrows(RuntimeException.class,
                () -> connector.post("http://localhost:9308/cli_json", "text/plain", "SELECT 1"));

        assertEquals("root cause", exception.getMessage());
    }

    private static class TestableManticoreConnector extends ManticoreConnector {
        TestableManticoreConnector(String host, int httpPort, int queryTimeout) {
            super(host, httpPort, queryTimeout);
        }
    }
}
