package manticore;

import kong.unirest.HttpRequestWithBody;
import kong.unirest.HttpResponse;
import kong.unirest.MultipartBody;
import kong.unirest.Unirest;
import org.json.JSONObject;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.Mock;
import org.mockito.MockedStatic;
import org.mockito.junit.jupiter.MockitoExtension;

import java.util.HashMap;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class ScalingMetricsTest {

    @Mock
    private Profiler mockProfiler;

    @Mock
    private HttpResponse<String> mockHttpResponse;

    @Mock
    private HttpRequestWithBody mockRequest;

    @Mock
    private MultipartBody mockMultipartBody;

    private ScalingMetrics scalingMetrics;

    private static final String SCALER_HOST = "localhost:8080";
    private static final String PROCESS_LABEL = "test_process";
    private static final Long MEASURE_TIME = 60000L; // 1 minute in milliseconds

    @BeforeEach
    void setUp() {
        // Create a mock WorkerConfig for testing
        WorkerConfig mockConfig = mock(WorkerConfig.class);
        when(mockConfig.getProcessLabel()).thenReturn(PROCESS_LABEL);
        when(mockConfig.getScalerHost()).thenReturn(SCALER_HOST);
        when(mockConfig.getProcessedMeasureTime()).thenReturn(MEASURE_TIME);

        scalingMetrics = spy(new ScalingMetrics(mockConfig));
    }

    @Test
    void testConstructor() {
        assertEquals(SCALER_HOST, scalingMetrics.scalerHost, "Scaler host should match constructor argument");
        assertEquals(PROCESS_LABEL, scalingMetrics.processLabel, "Process label should match constructor argument");
        assertEquals(MEASURE_TIME, scalingMetrics.measureTime, "Measure time should match constructor argument");
    }

    @Test
    void testSetters() {
        scalingMetrics.setLag(100L);
        scalingMetrics.setMaxBatchSize(50);
        scalingMetrics.setMinThreadsCount(2);
        scalingMetrics.setMaxThreadsCount(10);
        scalingMetrics.setBatchSize(20);
        scalingMetrics.setLastQueryProcessingTime(1.5f);

        assertEquals(100L, scalingMetrics.lag, "Lag should be set");
        assertEquals(50, scalingMetrics.maxBatchSize, "Max batch size should be set");
        assertEquals(2, scalingMetrics.minThreads, "Min threads should be set");
        assertEquals(10, scalingMetrics.maxThreads, "Max threads should be set");
        assertEquals(20, scalingMetrics.batchSize, "Batch size should be set");
        assertEquals(1.5f, scalingMetrics.lastQueryProcessingTime, "Last query processing time should be set");
    }

    @Test
    void testGetDocsPerSecond() {
        long currentTime = 1000000L;
        doReturn(currentTime).when(scalingMetrics).getCurrentTimeMillis();

        scalingMetrics.addProcessedTime(30); // t=1000000
        doReturn(currentTime - 30000L).when(scalingMetrics).getCurrentTimeMillis(); // t=970000
        scalingMetrics.addProcessedTime(20); // Within 60s
        doReturn(currentTime - 70000L).when(scalingMetrics).getCurrentTimeMillis(); // t=930000
        scalingMetrics.addProcessedTime(10); // Outside 60s

        doReturn(currentTime).when(scalingMetrics).getCurrentTimeMillis();
        Float docsPerSecond = scalingMetrics.getDocsPerSecond();

        assertEquals(50f / 60f, docsPerSecond, 0.0001f, "Docs per second should calculate correctly");
        assertEquals(2, scalingMetrics.queryProcessTime.size(), "Old entry should be removed");
    }

    @Test
    void testAddProcessedTime() {
        doReturn(1000000L).when(scalingMetrics).getCurrentTimeMillis();
        scalingMetrics.addProcessedTime(10);
        scalingMetrics.addProcessedTime(20); // Same timestamp, accumulates

        doReturn(1000001L).when(scalingMetrics).getCurrentTimeMillis();
        scalingMetrics.addProcessedTime(15);

        assertEquals(2, scalingMetrics.queryProcessTime.size(), "Should have two timestamp entries");
        assertEquals(30, scalingMetrics.queryProcessTime.get(1000000L), "Should accumulate at same timestamp");
        assertEquals(15, scalingMetrics.queryProcessTime.get(1000001L), "Should add new timestamp entry");
    }

    @Test
    void testSendMetrics_Success() {
        try (MockedStatic<Profiler> profiler = mockStatic(Profiler.class);
             MockedStatic<Unirest> unirest = mockStatic(Unirest.class)) {
            profiler.when(Profiler::getInstance).thenReturn(mockProfiler);

            scalingMetrics.setLag(100L);
            scalingMetrics.setMaxBatchSize(50);
            scalingMetrics.setMinThreadsCount(2);
            scalingMetrics.setMaxThreadsCount(10);
            scalingMetrics.setBatchSize(20);
            scalingMetrics.setLastQueryProcessingTime(1.5f);

            when(mockHttpResponse.getBody()).thenReturn("{\"batchSize\": 25}");
            unirest.when(() -> Unirest.post("http://" + SCALER_HOST + "/")).thenReturn(mockRequest);
            when(mockRequest.header("accept", "application/json")).thenReturn(mockRequest);
            when(mockRequest.fields(anyMap())).thenReturn(mockMultipartBody);
            when(mockMultipartBody.asString()).thenReturn(mockHttpResponse);

            Integer result = scalingMetrics.sendMetrics();

            assertEquals(25, result, "Should return batch size from response");
            verify(mockProfiler).clean();
        }
    }

    @Test
    void testSendMetrics_Failure() {
        try (MockedStatic<Profiler> profiler = mockStatic(Profiler.class);
             MockedStatic<Unirest> unirest = mockStatic(Unirest.class)) {
            profiler.when(Profiler::getInstance).thenReturn(mockProfiler);

            scalingMetrics.setMaxBatchSize(40);

            unirest.when(() -> Unirest.post("http://" + SCALER_HOST + "/")).thenReturn(mockRequest);
            when(mockRequest.header("accept", "application/json")).thenReturn(mockRequest);
            when(mockRequest.fields(anyMap())).thenThrow(new RuntimeException("HTTP failure"));

            Integer result = scalingMetrics.sendMetrics();

            assertNull(result, "Should return null on HTTP failure");
            verify(mockProfiler).clean();
        }
    }

    @Test
    void testParseResponse_ValidBatchSize() {
        String response = "{\"batchSize\": 30}";
        Integer result = scalingMetrics.parseResponse(response);
        assertEquals(30, result, "Should parse batchSize from valid response");
    }

    @Test
    void testParseResponse_NoBatchSize() {
        scalingMetrics.setMaxBatchSize(20);
        String response = "{\"other\": \"data\"}";
        Integer result = scalingMetrics.parseResponse(response);
        assertEquals(10, result, "Should return maxBatchSize / 2 when no batchSize in response");
    }

    @Test
    void testParseResponse_InvalidJson() {
        scalingMetrics.setMaxBatchSize(40);
        String response = "invalid json";
        Integer result = scalingMetrics.parseResponse(response);
        assertNull(result, "Should return null for invalid JSON");
    }
}