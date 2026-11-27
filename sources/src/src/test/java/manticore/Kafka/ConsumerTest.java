package manticore.Kafka;

import org.apache.kafka.clients.consumer.*;
import org.apache.kafka.common.TopicPartition;
import org.apache.kafka.common.errors.TimeoutException;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.ArgumentCaptor;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;

import java.time.Duration;
import java.util.*;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class ConsumerTest {

    @Mock
    private KafkaConsumer<String, String> mockKafkaConsumer;

    private Consumer consumer;

    @BeforeEach
    void setUp() {
        consumer = new Consumer(mockKafkaConsumer);
    }

    @Test
    void testConsumerInitialization() {
        assertNotNull(consumer);
    }

    @Test
    void testConsumerSubscribe() {
        List<String> topics = Arrays.asList("topic1", "topic2");

        consumer.subscribe(topics);

        ArgumentCaptor<List<String>> captor = ArgumentCaptor.forClass(List.class);
        verify(mockKafkaConsumer).subscribe(captor.capture(), any(ConsumerRebalanceListener.class));

        List<String> capturedTopics = captor.getValue();
        assertNotNull(capturedTopics);
        assertTrue(capturedTopics.contains("topic1"));
        assertTrue(capturedTopics.contains("topic2"));
    }

    @Test
    void testConsume() {
        ConsumerRecords<String, String> mockRecords = mock(ConsumerRecords.class);
        when(mockKafkaConsumer.poll(any(Duration.class))).thenReturn(mockRecords);

        ConsumerRecords<String, String> records = consumer.consume();
        assertEquals(mockRecords, records);
    }

    @Test
    void testAssignment() {
        Set<TopicPartition> mockAssignment = new HashSet<>();
        mockAssignment.add(new TopicPartition("test-topic", 0));
        when(mockKafkaConsumer.assignment()).thenReturn(mockAssignment);

        Set<TopicPartition> assignment = consumer.assignment();
        assertEquals(mockAssignment, assignment);
    }

    @Test
    void testEndOffsets() {
        Map<TopicPartition, Long> mockOffsets = new HashMap<>();
        mockOffsets.put(new TopicPartition("test-topic", 0), 100L);
        when(mockKafkaConsumer.endOffsets(any())).thenReturn(mockOffsets);

        Map<TopicPartition, Long> offsets = consumer.endOffsets(Collections.singleton(new TopicPartition("test-topic", 0)));
        assertEquals(mockOffsets, offsets);
    }

    @Test
    void testPosition() {
        TopicPartition mockPartition = new TopicPartition("test-topic", 0);
        when(mockKafkaConsumer.position(mockPartition)).thenReturn(50L);

        long position = consumer.position(mockPartition);
        assertEquals(50L, position);
    }

    @Test
    void testCommitSync_Success() {
        consumer.commitSync();
        verify(mockKafkaConsumer).commitSync();
    }

    @Test
    void testCommitSync_WithOffsets_Success() {
        Map<TopicPartition, OffsetAndMetadata> offsets = new HashMap<>();
        offsets.put(new TopicPartition("test-topic", 0), new OffsetAndMetadata(10L));
        when(mockKafkaConsumer.assignment()).thenReturn(offsets.keySet());
        consumer.commitSync(offsets);
        verify(mockKafkaConsumer).commitSync(offsets, Duration.ofMillis(5000L));
    }

    @Test
    void testCommitSync_WithRetries() {
        Map<TopicPartition, OffsetAndMetadata> offsets = new HashMap<>();
        offsets.put(new TopicPartition("test-topic", 0), new OffsetAndMetadata(10L));
        when(mockKafkaConsumer.assignment()).thenReturn(offsets.keySet());

        doThrow(new TimeoutException("Commit timeout"))
                .doNothing()
                .when(mockKafkaConsumer).commitSync(offsets, Duration.ofMillis(5000L));

        consumer.commitSync(offsets);

        verify(mockKafkaConsumer, times(2)).commitSync(offsets, Duration.ofMillis(5000L));
    }

    @Test
    void testCommitSync_ExceedsRetryLimit() {
        Map<TopicPartition, OffsetAndMetadata> offsets = new HashMap<>();
        offsets.put(new TopicPartition("test-topic", 0), new OffsetAndMetadata(10L));
        when(mockKafkaConsumer.assignment()).thenReturn(offsets.keySet());

        // Simulate continuous TimeoutException for more than 10 retries
        doThrow(new TimeoutException("Commit timeout"))
                .when(mockKafkaConsumer)
                .commitSync(offsets, Duration.ofMillis(5000L));

        // Call commitSync 10 times to exhaust retries
        for (int i = 0; i < 10; i++) {
            try {
                consumer.commitSync(offsets);
            } catch (TimeoutException ignored) {
                // Expected during retries
            }
        }

        // The 11th call should throw the exception
        assertThrows(TimeoutException.class, () -> consumer.commitSync(offsets));
    }

    @Test
    void testCommitAsync() {
        Map<TopicPartition, OffsetAndMetadata> offsets = new HashMap<>();
        offsets.put(new TopicPartition("test-topic", 0), new OffsetAndMetadata(10L));
        when(mockKafkaConsumer.assignment()).thenReturn(offsets.keySet());

        consumer.commitAsync(offsets);

        verify(mockKafkaConsumer).commitAsync(offsets, null);
    }

    @Test
    void testCommitSyncSkipsUnassignedPartitions() {
        TopicPartition owned = new TopicPartition("test-topic", 0);
        TopicPartition revoked = new TopicPartition("test-topic", 1);
        Map<TopicPartition, OffsetAndMetadata> offsets = Map.of(
                owned, new OffsetAndMetadata(20L), revoked, new OffsetAndMetadata(10L));
        when(mockKafkaConsumer.assignment()).thenReturn(Set.of(owned));

        consumer.commitSync(offsets);

        verify(mockKafkaConsumer).commitSync(Map.of(owned, offsets.get(owned)), Duration.ofMillis(5000L));
    }

    @Test
    void testCommitAsyncSkipsUnassignedPartitions() {
        TopicPartition owned = new TopicPartition("test-topic", 0);
        TopicPartition revoked = new TopicPartition("test-topic", 1);
        Map<TopicPartition, OffsetAndMetadata> offsets = Map.of(
                owned, new OffsetAndMetadata(20L), revoked, new OffsetAndMetadata(10L));
        when(mockKafkaConsumer.assignment()).thenReturn(Set.of(owned));

        consumer.commitAsync(offsets);

        verify(mockKafkaConsumer).commitAsync(Map.of(owned, offsets.get(owned)), null);
    }

    @Test
    void testDoesNotCommitWhenNoPartitionsAreAssigned() {
        Map<TopicPartition, OffsetAndMetadata> offsets = Map.of(
                new TopicPartition("test-topic", 1), new OffsetAndMetadata(10L));
        when(mockKafkaConsumer.assignment()).thenReturn(Collections.emptySet());

        consumer.commitSync(offsets);
        consumer.commitAsync(offsets);

        verify(mockKafkaConsumer, never()).commitSync(anyMap(), any(Duration.class));
        verify(mockKafkaConsumer, never()).commitAsync(anyMap(), any());
    }

    @Test
    void testRebalancingState() {
        consumer.rebalancingState = true;
        consumer.lastRebalancing = System.currentTimeMillis() - Consumer.REBALANCING_TIMEOUT - 1;

        assertTrue(consumer.checkIsAtRebalancingState(), "Consumer should be in rebalancing state");
    }

    @Test
    void testSubscribe() {
        List<String> topics = Arrays.asList("topic1", "topic2");

        consumer.subscribe(topics);

        ArgumentCaptor<List<String>> topicsCaptor = ArgumentCaptor.forClass(List.class);
        verify(mockKafkaConsumer).subscribe(topicsCaptor.capture(), any(ConsumerRebalanceListener.class));
        assertEquals(topics, topicsCaptor.getValue());

        ArgumentCaptor<ConsumerRebalanceListener> listenerCaptor = ArgumentCaptor.forClass(ConsumerRebalanceListener.class);
        verify(mockKafkaConsumer).subscribe(eq(topics), listenerCaptor.capture());

        ConsumerRebalanceListener listener = listenerCaptor.getValue();
        assertNotNull(listener);
        listener.onPartitionsRevoked(Collections.emptyList());
        assertFalse(consumer.checkIsAtRebalancingState(), "Rebalancing state should be false after revocation");
        listener.onPartitionsAssigned(Collections.emptyList());
        assertFalse(consumer.checkIsAtRebalancingState(), "Rebalancing state should be false after assignment");
    }

    @Test
    void testRevokedAndLostPartitionsAreRemovedFromConsumerOffsets() {
        TopicPartition revoked = new TopicPartition("test-topic", 0);
        TopicPartition lost = new TopicPartition("test-topic", 1);
        TopicPartition retained = new TopicPartition("test-topic", 2);
        consumer.recordOffset(revoked, 10L);
        consumer.recordOffset(lost, 20L);
        consumer.recordOffset(retained, 30L);
        consumer.subscribe(List.of("test-topic"));

        ArgumentCaptor<ConsumerRebalanceListener> listener = ArgumentCaptor.forClass(ConsumerRebalanceListener.class);
        verify(mockKafkaConsumer).subscribe(anyList(), listener.capture());
        listener.getValue().onPartitionsRevoked(List.of(revoked));
        listener.getValue().onPartitionsLost(List.of(lost));

        assertEquals(Map.of(retained, new OffsetAndMetadata(30L, "no metadata")), consumer.getOffsets());
    }

    @Test
    void testReturnedPartitionCannotCommitItsOldOffset() {
        TopicPartition partition = new TopicPartition("test-topic", 0);
        consumer.recordOffset(partition, 10L);
        consumer.subscribe(List.of("test-topic"));
        ArgumentCaptor<ConsumerRebalanceListener> listener = ArgumentCaptor.forClass(ConsumerRebalanceListener.class);
        verify(mockKafkaConsumer).subscribe(anyList(), listener.capture());

        listener.getValue().onPartitionsRevoked(List.of(partition));
        when(mockKafkaConsumer.assignment()).thenReturn(Set.of(partition));
        consumer.commitRecordedOffsets();

        assertTrue(consumer.getOffsets().isEmpty());
        verify(mockKafkaConsumer, never()).commitSync(anyMap(), any(Duration.class));
    }

    @Test
    void testGetOffsetsReturnsSnapshot() {
        TopicPartition partition = new TopicPartition("test-topic", 0);
        consumer.recordOffset(partition, 10L);

        consumer.getOffsets().clear();

        assertEquals(Map.of(partition, new OffsetAndMetadata(10L, "no metadata")), consumer.getOffsets());
    }
}