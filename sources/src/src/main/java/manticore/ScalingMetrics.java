package manticore;

import kong.unirest.Unirest;
import org.json.JSONObject;

import java.util.HashMap;
import java.util.Iterator;
import java.util.Map;

public class ScalingMetrics {
    protected Long lag;
    protected Float lastQueryProcessingTime;
    protected Integer batchSize;
    protected Integer maxBatchSize = 0;
    protected final HashMap<Long, Integer> queryProcessTime = new HashMap<>();
    protected final String scalerHost;
    protected final String processLabel;
    protected final Long measureTime;
    protected Integer minThreads = 0;
    protected Integer maxThreads = 0;

    public ScalingMetrics(WorkerConfig config) {
        this.measureTime = config.getProcessedMeasureTime();
        this.scalerHost = config.getScalerHost();
        this.processLabel = config.getProcessLabel();
    }

    public void setLag(Long lag) {
        this.lag = lag;
    }

    public void setMaxBatchSize(Integer maxBatchSize) {
        this.maxBatchSize = maxBatchSize;
    }

    public void setMinThreadsCount(Integer minThreads) {
        this.minThreads = minThreads;
    }

    public void setMaxThreadsCount(Integer maxThreads) {
        this.maxThreads = maxThreads;
    }

    public Float getDocsPerSecond() {
        Long minTime = getCurrentTimeMillis() - measureTime;
        float docsPerSecond = 0F;
        int perMinuteProcessed = 0;
        Iterator<Long> it = queryProcessTime.keySet().iterator();
        while (it.hasNext()) {
            Long key = it.next();
            if (key < minTime) {
                it.remove();
                continue;
            }
            Integer size = queryProcessTime.get(key);
            perMinuteProcessed += size;
        }
        if (perMinuteProcessed != 0) {
            float measureTimeSec = (float) (measureTime / 1000);
            docsPerSecond = perMinuteProcessed / measureTimeSec;
        }
        return docsPerSecond;
    }

    public void setBatchSize(Integer batchSize) {
        this.batchSize = batchSize;
    }

    public void addProcessedTime(Integer size) {
        Long now = getCurrentTimeMillis();
        Integer currentSize = size;
        if (queryProcessTime.containsKey(now)) {
            currentSize = queryProcessTime.get(now);
            currentSize += size;
        }
        queryProcessTime.put(now, currentSize);
    }

    public void setLastQueryProcessingTime(Float lastQueryProcessingTime) {
        this.lastQueryProcessingTime = lastQueryProcessingTime;
    }

    public Integer sendMetrics() {
        Map<String, Object> metrics = new HashMap<>();
        metrics.put("minThreads", minThreads);
        metrics.put("maxThreads", maxThreads);
        metrics.put("lag", lag);
        metrics.put("label", processLabel);
        metrics.put("lastQueryProcessingTime", lastQueryProcessingTime);
        metrics.put("batchSize", batchSize);
        metrics.put("maxBatchSize", maxBatchSize);

        Worker.getLogger().info("[ScalingMetrics] Sending metrics for process {} to scaler {}: Lag: {}, Label: {}, Batch size: {}/{}",
                processLabel, scalerHost, lag, processLabel, batchSize, maxBatchSize);

        Profiler.getInstance().clean();

        try {
            String response = Unirest.post("http://" + scalerHost + "/")
                    .header("accept", "application/json")
                    .fields(metrics)
                    .asString().getBody();
            return parseResponse(response);
        } catch (Exception e) {
            Worker.getLogger().warn("[ScalingMetrics] Failed to send metrics to scaler {}: {}", scalerHost, e.getMessage());
            Worker.getLogger().trace("[ScalingMetrics] Exception", e);
            return null;
        }
    }

    protected Integer parseResponse(String response) {
        try {
            JSONObject jsonResponse = new JSONObject(response);
            if (jsonResponse.has("batchSize")) {
                int responseBatch = jsonResponse.getInt("batchSize");
                if (responseBatch > 0) {
                    return responseBatch;
                }
            } else {
                return maxBatchSize / 2;
            }
        } catch (Exception e) {
            Worker.getLogger().info("[ScalingMetrics] Empty or invalid response from scaler {}: {}", scalerHost, e.getMessage());
        }
        return null;
    }

    protected long getCurrentTimeMillis() {
        return System.currentTimeMillis();
    }
}