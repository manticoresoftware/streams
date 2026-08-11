package manticore.Metrics;

import java.util.HashMap;

public interface MetricsStorage {
    void addToBatch(String metricName, HashMap<String, Number> metrics);
}
