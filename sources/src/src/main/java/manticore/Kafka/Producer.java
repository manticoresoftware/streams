package manticore.Kafka;

import manticore.WorkerConfig;
import org.apache.kafka.clients.producer.Callback;
import org.apache.kafka.clients.producer.KafkaProducer;
import org.apache.kafka.clients.producer.ProducerRecord;

import java.util.Properties;

import static manticore.Worker.getLogger;

public class Producer {
    private final KafkaProducer<String, String> producer;
    private final String topic;
    private Integer failedProducerSends = 0;
    private final int maxKafkaMessage;
    private final boolean skipExceededMessages;
    private final boolean isTestEnvironment;
    private int sent;

    public Producer(KafkaProducer<String, String> kafkaProducer, String topic, int maxKafkaMessage, boolean skipExceededMessages) {
        producer = kafkaProducer;
        this.topic = topic;
        this.maxKafkaMessage = maxKafkaMessage;
        this.skipExceededMessages = skipExceededMessages;
        isTestEnvironment = true;
    }

    public Producer(WorkerConfig config) {
        Properties props = new Properties();
        props.setProperty("bootstrap.servers", config.getOutputHost());
        props.setProperty("acks", "all");
        props.setProperty("retries", "2");
        props.setProperty("batch.size", "1000000");
        props.setProperty("linger.ms", "1");
        props.setProperty("buffer.memory", "33554432");
        props.put("key.serializer", "org.apache.kafka.common.serialization.StringSerializer");
        props.put("value.serializer", "org.apache.kafka.common.serialization.StringSerializer");

        producer = new KafkaProducer<>(props);
        this.topic = config.getOutputTopic();
        this.maxKafkaMessage = config.getMaxKafkaMessage();
        this.skipExceededMessages = config.isSkipExceededMessages();
        isTestEnvironment = false;
    }

    public void send(String data) {
        if (data.getBytes().length >= maxKafkaMessage) {
            if (!skipExceededMessages) {
                if (isTestEnvironment) {
                    throw new IllegalStateException("[KafkaProducer] Message exceeds maximum allowed size for topic " + topic);
                } else {
                    getLogger().error("[KafkaProducer] Message exceeds maximum allowed size for topic {}: exiting", topic);
                    System.exit(1);
                }
            }
        } else {
            producer.send(new ProducerRecord<>(topic, data), kafkaProducerCallback());
        }
    }

    public Callback kafkaProducerCallback() {
        return (metadata, e) -> {
            if (e != null) {
                this.decreaseSent();
                getLogger().warn("[KafkaProducer] Failed to send message to topic {} (attempt {}/3): {}",
                        topic, failedProducerSends + 1, e.getMessage());
                failedProducerSends++;
                if (failedProducerSends >= 3) {
                    if (isTestEnvironment) {
                        throw new IllegalStateException("[KafkaProducer] Sending failed for topic " + topic);
                    } else {
                        getLogger().error("[KafkaProducer] Sending failed for topic {} after 3 attempts: exiting", topic);
                        System.exit(1);
                    }
                }
            } else {
                failedProducerSends = 0;
            }
        };
    }

    public void increaseSent() {
        this.sent++;
    }

    protected void decreaseSent() {
        if (this.sent >= 1) {
            this.sent--;
        }
    }

    public int getSent() {
        return this.sent;
    }

    public void clearSent() {
        this.sent = 0;
    }

    public String getTopic() {
        return topic;
    }

    protected int getMaxKafkaMessage() {
        return maxKafkaMessage;
    }

    protected boolean isSkipExceededMessages() {
        return skipExceededMessages;
    }

    protected int getFailedProducerSends() {
        return failedProducerSends;
    }
}