package manticore.Metrics;

import manticore.Metrics.Batch.MetricsBatch;
import org.junit.jupiter.api.Test;

import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;

import static org.junit.jupiter.api.Assertions.*;

class MetricsBatchTest {

    @Test
    void testInitialization() {
        MetricsBatch batch = new MetricsBatch(items -> { }, 1000L, 5);

        assertNotNull(batch);
    }

    @Test
    void testFlushesWhenSizeReachesMaxSize() throws InterruptedException {
        List<String> processedItems = new ArrayList<>();
        CountDownLatch latch = new CountDownLatch(1);
        MetricsBatch batch = new MetricsBatch(items -> {
            processedItems.addAll(items);
            latch.countDown();
        }, 1000L, 3);

        batch.addToBatch("Item 1");
        batch.addToBatch("Item 2");
        batch.addToBatch("Item 3");

        assertTrue(latch.await(2, TimeUnit.SECONDS));
        assertEquals(List.of("Item 1", "Item 2", "Item 3"), processedItems);
    }

    @Test
    void testFlushUsesSnapshotInsteadOfSharedMutableList() throws InterruptedException {
        List<String> snapshots = new ArrayList<>();
        CountDownLatch firstLatch = new CountDownLatch(1);
        CountDownLatch secondLatch = new CountDownLatch(1);
        MetricsBatch batch = new MetricsBatch(items -> {
            snapshots.add(String.join(",", items));
            if (snapshots.size() == 1) {
                firstLatch.countDown();
            } else if (snapshots.size() == 2) {
                secondLatch.countDown();
            }
        }, 1000L, 2);

        batch.addToBatch("A");
        batch.addToBatch("B");
        assertTrue(firstLatch.await(2, TimeUnit.SECONDS));

        batch.addToBatch("C");
        batch.addToBatch("D");
        assertTrue(secondLatch.await(2, TimeUnit.SECONDS));

        assertEquals(List.of("A,B", "C,D"), snapshots);
    }

    @Test
    void testFlushesWhenTimeLimitExpires() throws InterruptedException {
        List<String> processedItems = new ArrayList<>();
        CountDownLatch latch = new CountDownLatch(1);

        TestMetricsBatch batch = new TestMetricsBatch(items -> {
            processedItems.addAll(items);
            latch.countDown();
        }, 1000L, 5);

        batch.addToBatch("Item 1");
        batch.setBatchSendTime(System.currentTimeMillis() - 2000);
        batch.addToBatch("Item 2");

        assertTrue(latch.await(2, TimeUnit.SECONDS));
        assertEquals(List.of("Item 1", "Item 2"), processedItems);
    }

    private static class TestMetricsBatch extends MetricsBatch {
        TestMetricsBatch(java.util.function.Consumer<List<String>> callback, Long timeLimit, Integer maxSize) {
            super(callback, timeLimit, maxSize);
        }

        void setBatchSendTime(long batchSendTime) {
            this.batchSendTime = batchSendTime;
        }
    }
}
