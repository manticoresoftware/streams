package manticore;

import java.util.HashMap;

public class Profiler {
    public static String KAFKA_CONSUME = "kafka.consume";
    public static String KAFKA_PRODUCE = "kafka.produce";
    public static String MANTICORE_CALL_PQ = "manticore.call_pq";
    public static String QUERY_HANDLING = "query.handle_all";
    public static String QUERY_MAPPING = "query.mapping";

    HashMap<String, Long> measures = new HashMap<>();
    HashMap<String, Float> metrics = new HashMap<>();

    private static volatile Profiler instance;
    private boolean isEnabled = false;

    private Profiler() {
    }

    public static Profiler getInstance() {
        Profiler result = instance;
        if (result != null) {
            return result;
        }
        synchronized (Profiler.class) {
            if (instance == null) {
                instance = new Profiler();
            }
            return instance;
        }
    }

    public void setEnabled(boolean enabled) {
        this.isEnabled = enabled;
        Worker.getLogger().debug("[Profiler] Profiling {}", enabled ? "enabled" : "disabled");
    }

    public boolean isEnabled() {
        return isEnabled;
    }

    public void start(String name) {
        if (!isEnabled) {
            return;
        }
        measures.put(name, System.currentTimeMillis());
        Worker.getLogger().debug("[Profiler] Started profiling for {}", name);
    }

    public void end(String name) {
        if (!isEnabled) {
            return;
        }
        try {
            long measureStartTime = measures.get(name);
            float time = (float) (System.currentTimeMillis() - measureStartTime) / 1000;
            Float value = metrics.get(name);
            if (value == null) {
                metrics.put(name, time);
            } else {
                value = value + time;
                metrics.put(name, value);
            }
            Worker.getLogger().debug("[Profiler] Ended profiling for {}, duration: {}s", name, time);
        } catch (Exception ignored) {
        }
    }

    public HashMap<String, Float> results() {
        if (!isEnabled) {
            return new HashMap<>();
        }
        return metrics;
    }

    public void clean() {
        if (!isEnabled) {
            return;
        }
        measures.clear();
        metrics.clear();
        Worker.getLogger().debug("[Profiler] Cleared profiling metrics");
    }
}