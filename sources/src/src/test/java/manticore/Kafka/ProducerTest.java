package manticore.Kafka;

import org.apache.kafka.clients.producer.Callback;
import org.apache.kafka.clients.producer.KafkaProducer;
import org.apache.kafka.clients.producer.ProducerRecord;
import org.apache.kafka.clients.producer.RecordMetadata;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.ArgumentCaptor;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class ProducerTest {

    @Mock
    private KafkaProducer<String, String> mockKafkaProducer;

    private Producer producer;

    @BeforeEach
    void setUp() {
        producer = new Producer(mockKafkaProducer, "test-topic", 1024, false);
    }

    @Test
    void testProducerInitialization() {
        assertNotNull(producer);
        assertEquals("test-topic", producer.getTopic());
        assertEquals(1024, producer.getMaxKafkaMessage());
        assertFalse(producer.isSkipExceededMessages());
    }

    @Test
    void testSendValidMessage() {
        String message = "valid message";

        producer.send(message);

        ArgumentCaptor<ProducerRecord<String, String>> captor = ArgumentCaptor.forClass(ProducerRecord.class);
        verify(mockKafkaProducer).send(captor.capture(), any(Callback.class));

        ProducerRecord<String, String> capturedRecord = captor.getValue();
        assertEquals("test-topic", capturedRecord.topic());
        assertEquals(message, capturedRecord.value());
    }

    @Test
    void testSendExceedingMessageWithSkip() {
        producer = spy(new Producer(mockKafkaProducer, "test-topic", 10, true)); // Very small max message size

        String largeMessage = "this message is too long";
        producer.send(largeMessage);

        verify(mockKafkaProducer, never()).send(any(ProducerRecord.class), any(Callback.class));
    }

    @Test
    void testSendExceedingMessageWithoutSkip() {
        producer = spy(new Producer(mockKafkaProducer, "test-topic", 10, false));
        String largeMessage = "this message is too long";

        assertThrows(IllegalStateException.class, () -> producer.send(largeMessage));
    }

    @Test
    void testKafkaProducerCallback_Success() {
        Callback callback = producer.kafkaProducerCallback();

        callback.onCompletion(mock(RecordMetadata.class), null);

        assertEquals(0, producer.getFailedProducerSends());
    }

    @Test
    void testKafkaProducerCallback_Error() {
        Callback callback = producer.kafkaProducerCallback();

        Exception mockException = new Exception("Kafka send error");
        callback.onCompletion(null, mockException);

        assertEquals(1, producer.getFailedProducerSends());
    }

    @Test
    void testIncreaseAndClearSent() {
        producer.increaseSent();
        assertEquals(1, producer.getSent());

        producer.clearSent();
        assertEquals(0, producer.getSent());
    }

    @Test
    void testDecreaseSent() {
        producer.increaseSent();
        producer.increaseSent();
        assertEquals(2, producer.getSent());

        producer.decreaseSent();
        assertEquals(1, producer.getSent());

        producer.decreaseSent();
        producer.decreaseSent(); // Should not go below 0
        assertEquals(0, producer.getSent());
    }

    @Test
    void testCallbackFailureExit() {
        Callback callback = producer.kafkaProducerCallback();

        RuntimeException mockException = new RuntimeException("Simulated failure");
        callback.onCompletion(null, mockException); // 1st failure
        callback.onCompletion(null, mockException); // 2nd failure
        assertThrows(IllegalStateException.class, () -> callback.onCompletion(null, mockException)); // 3rd failure
    }
}