package manticore;

import manticore.Metrics.PrometheusCollector;
import manticore.Metrics.MetricsStorage;

import java.sql.SQLException;
import java.util.ArrayList;
import java.util.HashMap;

import static manticore.Worker.getLogger;

public class RuntimeMetrics {
    public static String TYPE_ALL_DOCS = "_handler_processed_docs";
    public static String TYPE_PER_SECOND = "_worker_processed_per_sec";
    public static String TYPE_RULES = "_handler_processed_rules";
    public static String TYPE_RULE_COUNT = "_manticore_rules";
    public static String TYPE_MATCHED_DOCS = "_handler_matched_docs";
    public static String TYPE_LAG = "_consumer_lag";

    private final MetricsStorage metricsStorage;
    protected final ArrayList<Integer> processed = new ArrayList<>();

    public RuntimeMetrics(String label, MetricsStorage storage) throws InterruptedException, SQLException {
        RuntimeMetrics.TYPE_ALL_DOCS = label.concat(RuntimeMetrics.TYPE_ALL_DOCS);
        RuntimeMetrics.TYPE_PER_SECOND = label.concat(RuntimeMetrics.TYPE_PER_SECOND);
        RuntimeMetrics.TYPE_RULES = label.concat(RuntimeMetrics.TYPE_RULES);
        RuntimeMetrics.TYPE_RULE_COUNT = label.concat(RuntimeMetrics.TYPE_RULE_COUNT);
        RuntimeMetrics.TYPE_MATCHED_DOCS = label.concat(RuntimeMetrics.TYPE_MATCHED_DOCS);
        RuntimeMetrics.TYPE_LAG = label.concat(RuntimeMetrics.TYPE_LAG);
        this.metricsStorage = storage;
    }

    public void sendMetrics(String metricName, HashMap<String, Number> metrics) {
        this.metricsStorage.addToBatch(metricName, metrics);
    }

    public void sendProcessed() {
        try {
            int sum = 0;
            if (processed.size() > 0) {
                sum = processed.stream().mapToInt(Integer::intValue).sum();
            }
            PrometheusCollector.getInstance().set(PrometheusCollector.PROCESSED_DOCS, sum);
            HashMap<String, Number> values = new HashMap<>();
            getLogger().debug("[RuntimeMetrics] Sent {} processed documents for metric {}", sum, TYPE_ALL_DOCS);
            values.put("", sum);
            sendMetrics(RuntimeMetrics.TYPE_ALL_DOCS, values);
            processed.clear();
        } catch (Exception e) {
            getLogger().warn("[RuntimeMetrics] Failed to send processed metrics for {}: {}", TYPE_ALL_DOCS, e.getMessage());
        }
    }

    public void setProcessed(Integer processed) {
        this.processed.add(processed);
    }
}
