package manticore;

import ch.qos.logback.classic.Logger;
import com.jsoniter.JsonIterator;
import com.jsoniter.any.Any;
import com.jsoniter.output.JsonStream;
import manticore.Kafka.Producer;
import manticore.Metrics.PrometheusCollector;
import org.apache.commons.collections4.ListUtils;

import java.io.*;
import java.nio.charset.StandardCharsets;
import java.util.*;
import java.util.concurrent.TimeUnit;

public class ManticoreBatch {
    protected Long batchIngestingStartTime = 0L;
    private float ingestingTime = 0;
    protected List<String> batch = new ArrayList<>();
    protected Integer maxSize;
    private final Boolean matchingQueries;
    private final Producer producer;
    private final JsonTransformer jsonTransformer;
    private final JsltTransformer jsltTransformer;
    private final ManticoreConnector manticoreConnector;
    private final ManualGarbageCleaner manualGarbageCleaner;
    private final ScalingMetrics scalingMetrics;
    private final RuntimeMetrics runtimeMetrics;
    protected boolean locked;
    private final Logger logger = Worker.getLogger();
    protected List<String> sourceDocs = new ArrayList<>();
    protected final Map<String, Map<Integer, String>> sourceHighlightedDocs = new HashMap<>();
    protected Boolean resendRemain = false;
    protected List<String> remainingQueryData = new ArrayList<>();
    private int matchedQueries = 0;

    public ManticoreBatch(ManticoreConnector manticoreConnector, Integer maxSize, Boolean matchingQueries,
                          JsonTransformer jsonTransformer, JsltTransformer jsltTransformer, Producer producer,
                          ManualGarbageCleaner manualGarbageCleaner, ScalingMetrics scalingMetrics,
                          RuntimeMetrics runtimeMetrics) {
        this.maxSize = maxSize;
        this.matchingQueries = matchingQueries;
        this.producer = producer;
        this.jsltTransformer = jsltTransformer;
        this.jsonTransformer = jsonTransformer;
        this.manticoreConnector = manticoreConnector;
        this.manualGarbageCleaner = manualGarbageCleaner;
        this.scalingMetrics = scalingMetrics;
        this.runtimeMetrics = runtimeMetrics;
    }

    public boolean processByTimer() {
        boolean processed = false;
        if (!locked) {
            if (this.isStuck()) {
                process();
                processed = true;
            }
        }
        return processed;
    }

    public Boolean isStuck() {
        return (!this.batch.isEmpty() && System.currentTimeMillis() - batchIngestingStartTime > 10000);
    }

    public void setSize(Integer size) {
        this.maxSize = size;
    }

    public Integer getSize() {
        return this.maxSize;
    }

    public boolean stack(String value) {
        boolean processed = false;
        while (locked) {
            try {
                TimeUnit.SECONDS.sleep(1);
            } catch (Exception e) {
                break;
            }
            logger.info("[ManticoreBatch] Waiting for process to unlock for topic {}", producer.getTopic());
        }

        batch.add(value);

        if (!batch.isEmpty() && batch.size() >= maxSize) {
            process();
            processed = true;
        }

        return processed;
    }

    public void process() {
        locked = true;
        if (batchIngestingStartTime != 0L) {
            float queryTimeMillis = (float) (System.currentTimeMillis() - batchIngestingStartTime);
            ingestingTime = queryTimeMillis / 1000;
        }

        logger.debug("[ManticoreBatch] Starting batch processing of {} documents for topic {}", batch.size(), producer.getTopic());
        run();
        logger.trace("[ManticoreBatch] Completed batch processing for topic {}", producer.getTopic());

        batch.clear();
        sourceDocs.clear();
        remainingQueryData.clear();
        sourceHighlightedDocs.clear();
        resendRemain = false;
        jsonTransformer.clean();
        batchIngestingStartTime = System.currentTimeMillis();
        locked = false;
    }

    protected void run() {
        Profiler.getInstance().start(Profiler.QUERY_HANDLING);
        PrometheusCollector.getInstance().startMeasure(PrometheusCollector.PROFILE_BATCH_HANDLING_TIME);

        matchedQueries = 0;
        try {
            long startTime = System.currentTimeMillis();
            List<Object> jsonData = transformBatchToJson();
            String query = processQuery(jsonData);
            TreeMap<Integer, ArrayList<PQRow>> queryResults = executeManticoreQuery(query);

            handleQueryResults(queryResults, jsonData.size());
            sendMetricsAndCleanup(startTime, jsonData.size(), queryResults);

        } catch (Exception e) {
            logger.error("[ManticoreBatch] Failed to process batch for topic {}: {}", producer.getTopic(), e.getMessage());
            logger.trace("[ManticoreBatch] Exception: ", e);
        } finally {
            finalizeRun();
        }
    }

    protected List<Object> transformBatchToJson() throws Exception {
        logger.trace("[ManticoreBatch] Transforming batch to JSON for topic {}", producer.getTopic());
        PrometheusCollector.getInstance().startMeasure(PrometheusCollector.PROFILE_MAPPING_TIME);
        Profiler.getInstance().start(Profiler.QUERY_MAPPING);

        List<Object> json = new ArrayList<>();
        for (String item : batch) {
            Object jsonObject = JsonIterator.deserialize(jsonTransformer.transform(item), Object.class);
            String transformedQuery = getTransformedQuery();
            sourceDocs.add(transformedQuery);
            json.add(jsonObject);
        }
        jsonTransformer.clean();

        PrometheusCollector.getInstance().endMeasure(PrometheusCollector.PROFILE_MAPPING_TIME);
        Profiler.getInstance().end(Profiler.QUERY_MAPPING);
        return json;
    }

    private String getTransformedQuery() {
        try {
            return jsonTransformer.getOutputDocs();
        } catch (Exception e) {
            logger.warn("[ManticoreBatch] Failed to get transformed query: {}", e.getMessage());
            logger.trace("[ManticoreBatch] Exception: ", e);
            return "";
        }
    }

    private String processQuery(List<Object> json) {
        return JsonStream.serialize(json);
    }

    protected String rebuildQueryFromCurrentBatch() throws Exception {
        sourceDocs.clear();
        return processQuery(transformBatchToJson());
    }

    private String rebuildQueryForRetry() {
        try {
            return rebuildQueryFromCurrentBatch();
        } catch (Exception e) {
            throw new RuntimeException("Failed to rebuild query from reduced batch", e);
        }
    }

    protected TreeMap<Integer, ArrayList<PQRow>> executeManticoreQuery(String query) {
        TreeMap<Integer, ArrayList<PQRow>> queryResults = new TreeMap<>();
        String currentQuery = query;
        for (int attempt = 1; attempt <= 3; attempt++) {
            logger.debug("[ManticoreBatch] Attempting query execution (attempt {}/3) for topic {}", attempt, producer.getTopic());

            try {
                return executePQQuery(buildLowQuery(currentQuery));
            } catch (Exception e) {
                boolean chunkedForRetry = handleQueryExecutionError(e, currentQuery);
                if (isSyntaxError(e, currentQuery)) {
                    currentQuery = rebuildQueryForRetry();
                    continue;
                }
                if (chunkedForRetry) {
                    currentQuery = rebuildQueryForRetry();
                }
                if (attempt == 3) {
                    break;
                }
                logger.error("[ManticoreBatch] Error during query execution for topic {}: {}", producer.getTopic(), e.getMessage());
                logger.trace("[ManticoreBatch] Exception: ", e);
                sleepForRetry(attempt);
            }
        }

        return queryResults;
    }

    private String buildLowQuery(String query) throws Exception {
        String lowQuery = lowerPHP(query);
        if (lowQuery == null) {
            throw new Exception("PHP lowercase script failed");
        }
        return lowQuery;
    }

    private TreeMap<Integer, ArrayList<PQRow>> executePQQuery(String lowQuery) throws Exception {
        TreeMap<Integer, ArrayList<PQRow>> queryResults = new TreeMap<>();
        String command = buildPQCommand(lowQuery);
        Profiler.getInstance().start(Profiler.MANTICORE_CALL_PQ);
        PrometheusCollector.getInstance().startMeasure(PrometheusCollector.PROFILE_QUERY_TIME);

        queryResults = processQueryResults(manticoreConnector.executeCli(command).rows());

        PrometheusCollector.getInstance().endMeasure(PrometheusCollector.PROFILE_QUERY_TIME);
        Profiler.getInstance().end(Profiler.MANTICORE_CALL_PQ);
        return queryResults;
    }

    private String buildPQCommand(String lowQuery) {
        String escapedQuery = escapeSqlString(lowQuery);
        return "CALL PQ ('pq', ('" + escapedQuery + "'), 1 as docs, 1 as query)";
    }

    private TreeMap<Integer, ArrayList<PQRow>> processQueryResults(List<ManticoreCliRow> rows) throws Exception {
        TreeMap<Integer, ArrayList<PQRow>> queryResults = new TreeMap<>();

        for (ManticoreCliRow row : rows) {
            String toDocument = row.optString("documents");
            String hl = row.optString("tags");
            boolean highlighted = checkHighlighting(hl);
            String[] splitDocs = toDocument.split(",");

            if (highlighted) {
                processHighlightedDocs(row.optString("query"), splitDocs);
            }

            updateQueryResults(row, splitDocs, highlighted, queryResults);
        }
        return queryResults;
    }

    private boolean checkHighlighting(String hl) {
        if (!hl.isEmpty()) {
            Any highlightedKey = JsonIterator.deserialize(hl).asMap().get("highlighting");
            return highlightedKey != null && highlightedKey.toBoolean();
        }
        return false;
    }

    protected void processHighlightedDocs(String ruleQuery, String[] splitDocs) throws Exception {
        if (splitDocs == null || splitDocs.length == 0) {
            return;
        }

        String command = buildSnippetsCommand(ruleQuery, buildHighlightedJson(splitDocs));

        try {
            List<ManticoreCliRow> rows = manticoreConnector.executeCli(command).rows();
            Map<Integer, String> highlightedResult = sourceHighlightedDocs.computeIfAbsent(ruleQuery, key -> new HashMap<>());
            for (int docId = 0; docId < rows.size() && docId < splitDocs.length; docId++) {
                String highlightedResponse = rows.get(docId).optString("snippet");
                highlightedResult.put(Integer.parseInt(splitDocs[docId]), highlightedResponse);
            }
        } catch (Exception e) {
            logger.warn("[ManticoreBatch] Failed to process highlighted documents for topic {}: {}", producer.getTopic(), e.getMessage());
            logger.trace("[ManticoreBatch] Exception: ", e);
        }
    }

    private String buildSnippetsCommand(String ruleQuery, List<String> highlightedJson) {
        StringBuilder docsSql = new StringBuilder();
        for (int i = 0; i < highlightedJson.size(); i++) {
            if (i > 0) {
                docsSql.append(", ");
            }
            docsSql.append("'").append(escapeSqlString(highlightedJson.get(i))).append("'");
        }
        return "CALL SNIPPETS((" + docsSql + "), 'pq', '" + escapeSqlString(ruleQuery) + "', 0 as limit)";
    }

    private List<String> buildHighlightedJson(String[] splitDocs) {
        List<String> highlightedJson = new ArrayList<>(splitDocs.length);
        for (String documentNumber : splitDocs) {
            highlightedJson.add(JsonStream.serialize(batch.get(Integer.parseInt(documentNumber) - 1)));
        }
        return highlightedJson;
    }

    private void updateQueryResults(ManticoreCliRow row, String[] splitDocs, boolean highlighted,
                                    TreeMap<Integer, ArrayList<PQRow>> queryResults) {
        for (String s : splitDocs) {
            int documentNumber = Integer.parseInt(s);
            PQRow resultRow = new PQRow(row.optLong("id"), row.optString("query"),
                    row.optString("tags"), row.optString("filters"));

            if (highlighted) {
                resultRow.setHighlighted(true);
            }

            ArrayList<PQRow> resultsByDocuments = queryResults.getOrDefault(documentNumber, new ArrayList<>());
            resultsByDocuments.add(resultRow);
            queryResults.put(documentNumber, resultsByDocuments);
        }
    }

    private String escapeSqlString(String value) {
        return value.replace("\\", "\\\\").replace("'", "\\'");
    }

    private void handleQueryResults(TreeMap<Integer, ArrayList<PQRow>> queryResults, int processedSize) {
        if (resendRemain) {
            sendRemaining();
        }
        producer.clearSent();

        logger.trace("[ManticoreBatch] Handling matched queries for topic {}", producer.getTopic());
        HashMap<String, Number> matchedRulesStats = processMatchedQueries(queryResults);
        updateMetrics(matchedRulesStats, processedSize, queryResults);
    }

    protected HashMap<String, Number> processMatchedQueries(TreeMap<Integer, ArrayList<PQRow>> queryResults) {
        HashMap<String, Number> matchedRulesStats = new HashMap<>();

        for (Map.Entry<Integer, ArrayList<PQRow>> entry : queryResults.entrySet()) {
            ArrayList<PQRow> entryValue = entry.getValue();
            int docId = entry.getKey() - 1;
            matchedQueries += entryValue.size();
            String document = sourceDocs.get(docId);

            Map<String, Any> jsDocument = null;
            List<Map<String, Any>> rulesArray = new ArrayList<>();

            if (matchingQueries) {
                jsDocument = JsonIterator.deserialize(document).asMap();
            }

            entryValue.forEach(row -> {
                if (matchingQueries) {
                    Map<String, Any> appendValue = new HashMap<>();
                    appendValue.put("queryid", Any.wrap(row.getUID()));

                    try {
                        Map<String, Any> tags = JsonIterator.deserialize(row.getTags()).asMap();
                        Any tag = tags.get("tag");
                        if (tag != null) {
                            appendValue.put("tag", tag);
                        }

                        Any externalQuery = tags.get("externalquery");
                        if (externalQuery != null) {
                            appendValue.put("query", externalQuery);
                        } else {
                            appendValue.put("query", Any.wrap(row.getQuery()));
                            appendValue.put("filters", Any.wrap(row.getFilters()));
                        }
                    } catch (Exception e) {
                        appendValue.put("tag", Any.wrap(row.getTags()));
                        appendValue.put("query", Any.wrap(row.getQuery()));
                        appendValue.put("filters", Any.wrap(row.getFilters()));
                    }
                    rulesArray.add(appendValue);
                }

                if (row.getHighlighted()) {
                    String documentHighlighted;
                    try {
                        documentHighlighted = sourceHighlightedDocs.get(row.getQuery()).get(entry.getKey());
                    } catch (Exception e) {
                        logger.warn("[ManticoreBatch] Failed to get highlighted document for topic {}: {}", producer.getTopic(), e.getMessage());
                        return;
                    }

                    if (jsltTransformer != null) {
                        documentHighlighted = jsltTransformer.transform(documentHighlighted);
                    }
                    Profiler.getInstance().start(Profiler.KAFKA_PRODUCE);
                    PrometheusCollector.getInstance().startMeasure(PrometheusCollector.PROFILE_KAFKA_PRODUCE_TIME);
                    producer.send(documentHighlighted);
                    producer.increaseSent();
                    PrometheusCollector.getInstance().endMeasure(PrometheusCollector.PROFILE_KAFKA_PRODUCE_TIME);
                    Profiler.getInstance().end(Profiler.KAFKA_PRODUCE);
                }

                Number ruleValueInMatchList = matchedRulesStats.get(row.getUID().toString());
                if (ruleValueInMatchList == null) {
                    ruleValueInMatchList = 1;
                } else {
                    ruleValueInMatchList = 1 + ruleValueInMatchList.intValue();
                }
                matchedRulesStats.put(row.getUID().toString(), ruleValueInMatchList);
            });

            if (matchingQueries) {
                jsDocument.put("matching_queries", Any.wrap(rulesArray));
                document = JsonStream.serialize(jsDocument);
            }

            if (jsltTransformer != null) {
                document = jsltTransformer.transform(document);
            }

            Profiler.getInstance().start(Profiler.KAFKA_PRODUCE);
            PrometheusCollector.getInstance().startMeasure(PrometheusCollector.PROFILE_KAFKA_PRODUCE_TIME);
            producer.send(document);
            producer.increaseSent();
            PrometheusCollector.getInstance().endMeasure(PrometheusCollector.PROFILE_KAFKA_PRODUCE_TIME);
            Profiler.getInstance().end(Profiler.KAFKA_PRODUCE);
        }

        return matchedRulesStats;
    }

    private void updateMetrics(HashMap<String, Number> matchedRulesStats, int processedSize, TreeMap<Integer, ArrayList<PQRow>> queryResults) {
        PrometheusCollector.getInstance().set(PrometheusCollector.MATCHED_DOCS, queryResults.size());
        PrometheusCollector.getInstance().set(PrometheusCollector.PROCESSED_DOCS, processedSize);
        PrometheusCollector.getInstance().set(PrometheusCollector.SEND_COMMITTED, producer.getSent());

        HashMap<String, Number> matchedRulesCount = new HashMap<>();
        Integer[] matchedRules = new Integer[]{0};
        matchedRulesStats.forEach((ruleid, val) -> matchedRules[0] += val.intValue());
        matchedRulesCount.put("", matchedRules[0]);

        runtimeMetrics.sendMetrics(RuntimeMetrics.TYPE_MATCHED_DOCS, matchedRulesCount);
        runtimeMetrics.sendMetrics(RuntimeMetrics.TYPE_RULES, matchedRulesStats);
        runtimeMetrics.setProcessed(processedSize);
    }

    private void sendMetricsAndCleanup(long startTime, int processedSize, TreeMap<Integer, ArrayList<PQRow>> queryResults) {
        float queryTime = ((float) (System.currentTimeMillis() - startTime) / 1000);
        logger.info("[ManticoreBatch] Processed batch for topic {}: Ingest time={}s, Processing time={}s, Size={}, Matched={}",
                producer.getTopic(), ingestingTime, queryTime, processedSize, matchedQueries);

        scalingMetrics.setLastQueryProcessingTime(queryTime);
        scalingMetrics.addProcessedTime(processedSize);

        updateMetrics(new HashMap<>(), processedSize, queryResults);
    }

    private void finalizeRun() {
        manualGarbageCleaner.setLastQueueHandled();
        PrometheusCollector.getInstance().endMeasure(PrometheusCollector.PROFILE_BATCH_HANDLING_TIME);
        Profiler.getInstance().end(Profiler.QUERY_HANDLING);
    }

    private boolean isSyntaxError(Exception e, String query) {
        String message = e.getMessage();
        if (message == null) {
            return false;
        }
        if (message.contains("unknown local index")) {
            try {
                sleepSeconds(30);
                logger.warn("[ManticoreBatch] Paused 30 seconds due to unknown local index for topic {}: {}", producer.getTopic(), message);
            } catch (InterruptedException ie) {
                logger.warn("[ManticoreBatch] Interrupted during 30-second pause for topic {}", producer.getTopic());
                logger.trace("[ManticoreBatch] Exception: ", e);
            }
            return false;
        }

        if (message.contains("syntax error") || message.contains("ERRORS:")) {
            logger.warn("[ManticoreBatch] SQL syntax error for topic {}, reducing batch size: {}", producer.getTopic(), message);
            final byte[] utf8Bytes = query.getBytes(StandardCharsets.UTF_8);
            logger.debug("[ManticoreBatch] Error query size: {} bytes for topic {}", utf8Bytes.length, producer.getTopic());
            return chunkBatch();
        }

        return false;
    }

    private boolean handleQueryExecutionError(Exception e, String query) {
        String message = e.getMessage();
        if (message != null && message.contains("Packet for query is too large")) {
            final byte[] utf8Bytes = query.getBytes(StandardCharsets.UTF_8);
            logger.warn("[ManticoreBatch] Packet too large for topic {}, reducing batch size. Query size: {} bytes, error: {}",
                    producer.getTopic(), utf8Bytes.length, message);
            return chunkBatch();
        }
        return false;
    }

    private void sleepForRetry(int attempts) {
        if (attempts > 1) {
            try {
                sleepSeconds(1);
                logger.info("[ManticoreBatch] Paused 1 second before retrying batch processing (attempt {}/3) for topic {}", attempts, producer.getTopic());
            } catch (Exception e) {
                // Ignore
            }
        }
    }

    protected void sleepSeconds(long seconds) throws InterruptedException {
        TimeUnit.SECONDS.sleep(seconds);
    }

    protected boolean chunkBatch() {
        this.resendRemain = true;
        int currentSize = batch.size();
        if (currentSize == 0) {
            logger.info("[ManticoreBatch] Skipping chunking for empty batch on topic {}", producer.getTopic());
            return false;
        }

        int newSize = (int) (currentSize * 0.9);

        if (newSize < 1) {
            newSize = 1;
        }

        maxSize = newSize;
        scalingMetrics.setMaxBatchSize(newSize);

        List<String> newSourceDocs = new ArrayList<>(sourceDocs.subList(0, newSize));
        List<String> newQueryData = new ArrayList<>(batch.subList(0, newSize));
        List<String> remainingQueryData = new ArrayList<>(batch.subList(newSize, currentSize));

        batch = newQueryData;
        sourceDocs = newSourceDocs;
        this.remainingQueryData = ListUtils.union(remainingQueryData, this.remainingQueryData);
        logger.info("[ManticoreBatch] Chunked batch to {} documents, remaining {} for topic {}", newSize, remainingQueryData.size(), producer.getTopic());
        return true;
    }

    protected void sendRemaining() {
        if (!remainingQueryData.isEmpty()) {
            setSize(maxSize);
            for (String value : remainingQueryData) {
                stack(value);
            }
            remainingQueryData.clear();
            logger.info("[ManticoreBatch] Sent remaining {} documents for topic {}", maxSize, producer.getTopic());
        }
        resendRemain = false;
    }

    public String lowerPHP(String content) {
        ProcessBuilder processBuilder = new ProcessBuilder();
        processBuilder.command("php", "-r", "echo mb_strtolower(file_get_contents('php://stdin'));");

        try {
            Process process = processBuilder.start();
            OutputStream outputStream = process.getOutputStream();

            byte[] buffer = content.getBytes();
            outputStream.write(buffer);
            outputStream.close();

            StringBuilder output = new StringBuilder();
            BufferedReader reader = new BufferedReader(new InputStreamReader(process.getInputStream()));

            String line;
            while ((line = reader.readLine()) != null) {
                output.append(line);
            }

            int exitVal = process.waitFor();
            if (exitVal == 0) {
                return output.toString();
            } else {
                logger.error("[ManticoreBatch] PHP lowercase script execution failed for topic {}", producer.getTopic());
            }
        } catch (IOException | InterruptedException e) {
            logger.error("[ManticoreBatch] Failed to execute PHP lowercase script for topic {}: {}", producer.getTopic(), e.getMessage());
            logger.trace("[ManticoreBatch] Exception: ", e);
        }
        return null;
    }
}
