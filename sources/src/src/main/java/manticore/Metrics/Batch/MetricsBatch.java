package manticore.Metrics.Batch;

import java.util.ArrayList;
import java.util.List;
import java.util.function.Consumer;

public class MetricsBatch {
    protected List<String> batch;
    protected Long timeLimit;
    protected Integer maxSize;
    protected Consumer<List<String>> callback;
    protected Long batchSendTime;

    public MetricsBatch(Consumer<List<String>> callback, Long timeLimit, Integer maxSize) {
        this.timeLimit = timeLimit;
        this.maxSize = maxSize;
        this.callback = callback;
        batch = new ArrayList<>();
        batchSendTime = System.currentTimeMillis();
    }

    public void addToBatch(String value) {
        batch.add(value);

        long batchedTime = batchSendTime + timeLimit;
        long nowTime = System.currentTimeMillis();
        if (!batch.isEmpty() && (batch.size() >= maxSize || nowTime > batchedTime)) {
            List<String> batchSnapshot = new ArrayList<>(batch);
            batch.clear();
            Thread thread = new Thread(() -> callback.accept(batchSnapshot));
            thread.start();
            batchSendTime = System.currentTimeMillis();
        }
    }
}
