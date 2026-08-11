package manticore.Metrics;

import ch.qos.logback.classic.Logger;
import manticore.ManticoreCliResult;
import manticore.ManticoreConnector;
import manticore.Metrics.Batch.MetricsBatch;
import manticore.WorkerConfig;
import org.json.JSONObject;
import org.slf4j.LoggerFactory;

import java.text.DateFormat;
import java.text.SimpleDateFormat;
import java.util.*;

public class ManticoreColumnar implements MetricsStorage {
    private static final long RETRY_INTERVAL_MS = 60000L;
    private static final DateFormat SDF = new SimpleDateFormat("yyyy-MM-dd HH:mm:ss");

    private final Logger logger;
    private final String host;
    protected MetricsBatch batch;
    private long lastCheck = 0L;
    private boolean connectionAvailable = false;
    private boolean tableChecked = false;
    private final ManticoreConnector connector;

    public ManticoreColumnar(WorkerConfig config) {
        host = config.getMetricsStorageHost();
        this.connector = new ManticoreConnector(
                config.getMetricsStorageHost(),
                config.getMetricsStoragePort(),
                config.getManticoreQueryTimeout()
        );
        this.logger = (Logger) LoggerFactory.getLogger(Logger.ROOT_LOGGER_NAME);
        batch = new MetricsBatch(this::run, 10000L, 5000);
        checkTable();
        SDF.setTimeZone(TimeZone.getTimeZone("UTC"));
    }

    public void run(List<String> textMetrics) {
        if (!textMetrics.isEmpty()) {
            int affected = bulkInsert(textMetrics);
            if (affected == 0) {
                logger.warn("[ManticoreColumnar] Inserted 0 rows for {} metrics at host {}", textMetrics.size(), host);
            }
        }
    }

    protected int bulkInsert(List<String> textMetrics) {
        if (!shouldAttemptRequest()) {
            return 0;
        }

        try {
            StringBuilder ndjson = new StringBuilder();
            for (String row : textMetrics) {
                ndjson.append(row).append("\n");
            }
            connector.executeBulk(ndjson.toString());
            this.connectionAvailable = true;
            return textMetrics.size();
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            this.connectionAvailable = false;
            setLastCheck();
            logger.error("[ManticoreColumnar] Interrupted during bulk insert for host {}: {}", host, e.getMessage());
            logger.trace("[ManticoreColumnar] Exception during bulk insert", e);
            return 0;
        } catch (RuntimeException e) {
            this.connectionAvailable = false;
            setLastCheck();
            logger.error("[ManticoreColumnar] Failed bulk insert for {} metrics at host {}: {}", textMetrics.size(), host, e.getMessage());
            logger.trace("[ManticoreColumnar] Exception during bulk insert", e);
            return 0;
        }
    }

    protected ManticoreCliResult executeCli(String query) {
        if (!shouldAttemptRequest()) {
            return null;
        }

        try {
            connectionAvailable = true;
            return connector.executeCli(query);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            connectionAvailable = false;
            setLastCheck();
            logger.error("[ManticoreColumnar] Interrupted during columnar cli query. Host: {}, Message: {}", host, e.getMessage());
            logger.trace("[ManticoreColumnar] Interrupted during columnar cli query: {}", query, e);
            return null;
        } catch (RuntimeException e) {
            connectionAvailable = false;
            setLastCheck();
            logger.error("[ManticoreColumnar] RuntimeException during columnar cli query. Host: {}, Message: {}", host, e.getMessage());
            logger.trace("[ManticoreColumnar] RuntimeException during columnar cli query: {}", query, e);
            return null;
        }
    }

    @Override
    public void addToBatch(String metricName, HashMap<String, Number> metrics) {
        long scrapTime = System.currentTimeMillis() / 1000L;
        for (Map.Entry<String, Number> entry : metrics.entrySet()) {
            String name = entry.getKey();
            Number value = entry.getValue();
            if (Objects.equals(name, "")) {
                name = "0";
            }
            if (value != null) {
                JSONObject doc = new JSONObject();
                doc.put("metric_name", metricName);
                doc.put("scraptime", scrapTime);
                doc.put("tag", name);
                doc.put("value", value);

                JSONObject insert = new JSONObject();
                insert.put("table", "metrics");
                insert.put("doc", doc);

                JSONObject payload = new JSONObject();
                payload.put("insert", insert);
                batch.addToBatch(payload.toString());
            }
        }
    }

    protected String getTableCode() {
        return "CREATE TABLE metrics (metric_name text, scraptime bigint, tag bigint, value float) engine='columnar'";
    }

    protected void checkTable() {
        if (!tableChecked) {
            try {
                boolean tableExists = isTableExists();
                if (!tableExists) {
                    executeCli(getTableCode());
                    tableExists = isTableExists();
                    if (tableExists) {
                        logger.info("[ManticoreColumnar] Created metrics table at host {}", host);
                    } else {
                        connectionAvailable = false;
                        setLastCheck();
                        logger.warn("[ManticoreColumnar] Metrics table is absent after create attempt at host {}", host);
                        return;
                    }
                }
                tableChecked = true;
                connectionAvailable = true;
            } catch (Exception e) {
                connectionAvailable = false;
                setLastCheck();
                logger.error("[ManticoreColumnar] Failed to check/create metrics table at host {}: {}", host, e.getMessage());
                logger.trace("[ManticoreColumnar] Exception during table check/creation, host {}", host, e);
            }
        }
    }

    private void setLastCheck() {
        lastCheck = System.currentTimeMillis();
    }

    protected boolean isTableExists() {
        ManticoreCliResult result = executeCli("SHOW TABLES");
        if (result != null && result.hasRowWithValue("Table", "metrics")) {
            tableChecked = true;
            return true;
        }
        return false;
    }

    public String getHost() {
        return host;
    }

    private boolean shouldAttemptRequest() {
        return connectionAvailable || System.currentTimeMillis() - lastCheck > RETRY_INTERVAL_MS;
    }
}
