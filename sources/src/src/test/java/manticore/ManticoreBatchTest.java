package manticore;

import io.prometheus.client.CollectorRegistry;
import manticore.Kafka.Producer;
import manticore.Metrics.PrometheusCollector;
import org.json.JSONArray;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.Map;
import java.util.TreeMap;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class ManticoreBatchTest {

    @Mock private ManticoreConnector manticoreConnector;
    @Mock private Producer producer;
    @Mock private JsonTransformer jsonTransformer;
    @Mock private JsltTransformer jsltTransformer;
    @Mock private ManualGarbageCleaner manualGarbageCleaner;
    @Mock private ScalingMetrics scalingMetrics;
    @Mock private RuntimeMetrics runtimeMetrics;

    private ManticoreBatch manticoreBatch;

    @BeforeEach
    void setUp() {
        CollectorRegistry.defaultRegistry.clear();
        PrometheusCollector collector = PrometheusCollector.getInstance();
        collector.addLabels(PrometheusCollector.PROFILE_BATCH_HANDLING_TIME, "testInstance", "testPipeline", "testGroup");
        collector.addLabels(PrometheusCollector.PROFILE_MAPPING_TIME, "testInstance", "testPipeline", "testGroup");
        collector.addLabels(PrometheusCollector.PROFILE_QUERY_TIME, "testInstance", "testPipeline", "testGroup");
        collector.addLabels(PrometheusCollector.PROFILE_KAFKA_PRODUCE_TIME, "testInstance", "testPipeline", "testGroup");
        collector.addLabels(PrometheusCollector.MATCHED_DOCS, "testInstance", "testPipeline", "testGroup");
        collector.addLabels(PrometheusCollector.PROCESSED_DOCS, "testInstance", "testPipeline", "testGroup");
        collector.addLabels(PrometheusCollector.SEND_COMMITTED, "testInstance", "testPipeline", "testGroup");

        manticoreBatch = spy(new ManticoreBatch(
                manticoreConnector, 10, true, jsonTransformer, jsltTransformer,
                producer, manualGarbageCleaner, scalingMetrics, runtimeMetrics
        ));
    }

    @AfterEach
    void tearDown() {
        CollectorRegistry.defaultRegistry.clear();
    }

    @Test
    void testTransformBatchToJson() throws Exception {
        manticoreBatch.batch.add("item1");
        manticoreBatch.batch.add("item2");
        when(jsonTransformer.transform("item1")).thenReturn("\"transformed1\"");
        when(jsonTransformer.transform("item2")).thenReturn("\"transformed2\"");
        when(jsonTransformer.getOutputDocs()).thenReturn("\"outputDoc\"");

        assertEquals(2, manticoreBatch.transformBatchToJson().size());
        assertEquals(2, manticoreBatch.sourceDocs.size());
        verify(jsonTransformer).clean();
    }

    @Test
    void testExecuteManticoreQuery_Success() throws Exception {
        when(manticoreConnector.executeCli(anyString())).thenReturn(pqRowsResult(
                new HashMap<>(Map.of("id", 123L, "documents", "1", "query", "query text", "tags", "", "filters", "filters"))
        ));
        doReturn("lowered query").when(manticoreBatch).lowerPHP("serialized query");

        TreeMap<Integer, ArrayList<PQRow>> results = manticoreBatch.executeManticoreQuery("serialized query");

        assertEquals(1, results.size());
        assertEquals(123L, results.get(1).get(0).getUID());
    }

    @Test
    void testExecuteManticoreQuery_SyntaxErrorChunksAndRetries() throws Exception {
        manticoreBatch.batch.add("doc1");
        manticoreBatch.batch.add("doc2");
        manticoreBatch.sourceDocs.add("source1");
        manticoreBatch.sourceDocs.add("source2");
        doReturn("lowered query").when(manticoreBatch).lowerPHP("serialized query");
        doReturn("reduced query").when(manticoreBatch).rebuildQueryFromCurrentBatch();
        doReturn("lowered reduced query").when(manticoreBatch).lowerPHP("reduced query");
        when(manticoreConnector.executeCli(anyString()))
                .thenThrow(new RuntimeException("syntax error near test"))
                .thenReturn(pqRowsResult(
                        new HashMap<>(Map.of("id", 321L, "documents", "1", "query", "query text", "tags", "", "filters", "filters"))
                ));

        TreeMap<Integer, ArrayList<PQRow>> results = manticoreBatch.executeManticoreQuery("serialized query");

        assertEquals(1, results.size());
        verify(manticoreBatch).chunkBatch();
        verify(manticoreBatch).rebuildQueryFromCurrentBatch();
        verify(manticoreConnector).executeCli("CALL PQ ('pq', ('lowered query'), 1 as docs, 1 as query)");
        verify(manticoreConnector).executeCli("CALL PQ ('pq', ('lowered reduced query'), 1 as docs, 1 as query)");
        verify(manticoreConnector, times(2)).executeCli(anyString());
    }

    @Test
    void testExecuteManticoreQuery_PacketTooLargeChunksAndRetries() throws Exception {
        manticoreBatch.batch.add("doc1");
        manticoreBatch.batch.add("doc2");
        manticoreBatch.sourceDocs.add("source1");
        manticoreBatch.sourceDocs.add("source2");
        doReturn("lowered query").when(manticoreBatch).lowerPHP("serialized query");
        doReturn("reduced query").when(manticoreBatch).rebuildQueryFromCurrentBatch();
        doReturn("lowered reduced query").when(manticoreBatch).lowerPHP("reduced query");
        when(manticoreConnector.executeCli(anyString()))
                .thenThrow(new RuntimeException("Packet for query is too large"))
                .thenReturn(pqRowsResult(
                        new HashMap<>(Map.of("id", 456L, "documents", "1", "query", "query text", "tags", "", "filters", "filters"))
                ));

        TreeMap<Integer, ArrayList<PQRow>> results = manticoreBatch.executeManticoreQuery("serialized query");

        assertEquals(1, results.size());
        verify(manticoreBatch).chunkBatch();
        verify(manticoreBatch).rebuildQueryFromCurrentBatch();
        verify(manticoreConnector).executeCli("CALL PQ ('pq', ('lowered query'), 1 as docs, 1 as query)");
        verify(manticoreConnector).executeCli("CALL PQ ('pq', ('lowered reduced query'), 1 as docs, 1 as query)");
        verify(manticoreConnector, times(2)).executeCli(anyString());
    }

    @Test
    void testExecuteManticoreQuery_UnknownLocalIndexSleepsAndRetries() throws Exception {
        doReturn("lowered query").when(manticoreBatch).lowerPHP("serialized query");
        doNothing().when(manticoreBatch).sleepSeconds(30);
        when(manticoreConnector.executeCli(anyString()))
                .thenThrow(new RuntimeException("unknown local index pq"))
                .thenReturn(pqRowsResult(
                        new HashMap<>(Map.of("id", 654L, "documents", "1", "query", "query text", "tags", "", "filters", "filters"))
                ));

        TreeMap<Integer, ArrayList<PQRow>> results = manticoreBatch.executeManticoreQuery("serialized query");

        assertEquals(1, results.size());
        assertEquals(654L, results.get(1).get(0).getUID());
        verify(manticoreBatch).sleepSeconds(30);
        verify(manticoreBatch, never()).chunkBatch();
        verify(manticoreConnector, times(2)).executeCli(anyString());
    }

    @Test
    void testExecuteManticoreQuery_ReturnsEmptyWhenLowerPhpFails() throws Exception {
        doReturn(null).when(manticoreBatch).lowerPHP("serialized query");

        TreeMap<Integer, ArrayList<PQRow>> results = manticoreBatch.executeManticoreQuery("serialized query");

        assertTrue(results.isEmpty());
        verify(manticoreConnector, never()).executeCli(anyString());
    }

    @Test
    void testChunkBatch() {
        for (int i = 0; i < 10; i++) {
            manticoreBatch.batch.add("data" + i);
            manticoreBatch.sourceDocs.add("source" + i);
        }

        manticoreBatch.chunkBatch();

        assertEquals(9, manticoreBatch.batch.size());
        assertEquals(1, manticoreBatch.remainingQueryData.size());
        verify(scalingMetrics).setMaxBatchSize(9);
    }

    @Test
    void testSendRemaining() {
        manticoreBatch.remainingQueryData.add("remaining1");
        manticoreBatch.remainingQueryData.add("remaining2");

        manticoreBatch.sendRemaining();

        assertTrue(manticoreBatch.remainingQueryData.isEmpty());
        assertEquals(2, manticoreBatch.batch.size());
    }

    @Test
    void testProcessMatchedQueries() {
        TreeMap<Integer, ArrayList<PQRow>> queryResults = new TreeMap<>();
        ArrayList<PQRow> rows = new ArrayList<>();
        rows.add(new PQRow(1L, "query", "{}", "filters"));
        queryResults.put(1, rows);
        manticoreBatch.sourceDocs.add("{\"key\":\"doc1\"}");
        when(jsltTransformer.transform(anyString())).thenReturn("{\"transformed\":\"doc1\"}");

        HashMap<String, Number> stats = manticoreBatch.processMatchedQueries(queryResults);

        assertEquals(1, stats.get("1"));
        verify(producer, atLeastOnce()).send(anyString());
    }

    @Test
    void testStack_LockedState() throws InterruptedException {
        manticoreBatch.locked = true;
        CountDownLatch latch = new CountDownLatch(1);

        new Thread(() -> {
            try {
                Thread.sleep(300);
                manticoreBatch.locked = false;
                latch.countDown();
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
            }
        }).start();

        assertFalse(manticoreBatch.stack("test data"));
        assertTrue(latch.await(2, TimeUnit.SECONDS));
        assertTrue(manticoreBatch.batch.contains("test data"));
    }

    @Test
    void testProcessByTimer_EmptyBatch() {
        assertFalse(manticoreBatch.processByTimer());
        verify(manticoreBatch, never()).process();
    }

    @Test
    void testProcessHighlightedDocs_Success() throws Exception {
        manticoreBatch.batch.add("doc1");
        manticoreBatch.batch.add("doc2");
        when(manticoreConnector.executeCli(anyString())).thenReturn(snippetRows("highlighted_doc1", "highlighted_doc2"));

        manticoreBatch.processHighlightedDocs("query text", new String[]{"1", "2"});

        Map<Integer, String> highlightedResult = manticoreBatch.sourceHighlightedDocs.get("query text");
        assertNotNull(highlightedResult);
        assertEquals("highlighted_doc1", highlightedResult.get(1));
        assertEquals("highlighted_doc2", highlightedResult.get(2));
    }

    @Test
    void testProcessHighlightedDocs_LeavesStateUntouchedWhenSnippetsFail() throws Exception {
        manticoreBatch.batch.add("doc1");
        when(manticoreConnector.executeCli(anyString())).thenThrow(new RuntimeException("snippets failed"));

        manticoreBatch.processHighlightedDocs("query text", new String[]{"1"});

        assertNull(manticoreBatch.sourceHighlightedDocs.get("query text"));
    }

    @Test
    void testManticoreBatchStoresConnectorConfig() {
        ManticoreConnector connector = new ManticoreConnector("localhost:9306", 9308, 300);
        ManticoreBatch batch = new ManticoreBatch(connector, 10, true, jsonTransformer, jsltTransformer,
                producer, manualGarbageCleaner, scalingMetrics, runtimeMetrics);

        assertNotNull(batch);
        assertEquals("localhost:9306", connector.getManticoreHost());
        assertEquals(300, connector.getQueryTimeout());
    }

    private ManticoreCliResult pqRowsResult(Map<String, Object>... rows) {
        JSONArray data = new JSONArray();
        for (Map<String, Object> row : rows) {
            data.put(row);
        }
        JSONArray resultSets = new JSONArray();
        resultSets.put(new HashMap<>(Map.of("data", data)));
        return new ManticoreCliResult(resultSets);
    }

    private ManticoreCliResult snippetRows(String... values) {
        JSONArray data = new JSONArray();
        for (String value : values) {
            data.put(new HashMap<>(Map.of("snippet", value)));
        }
        JSONArray resultSets = new JSONArray();
        resultSets.put(new HashMap<>(Map.of("data", data)));
        return new ManticoreCliResult(resultSets);
    }
}
