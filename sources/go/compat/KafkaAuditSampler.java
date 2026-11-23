import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.node.ArrayNode;
import com.fasterxml.jackson.databind.node.ObjectNode;
import org.apache.kafka.clients.consumer.ConsumerRecord;
import org.apache.kafka.clients.consumer.ConsumerRecords;
import org.apache.kafka.clients.consumer.KafkaConsumer;
import org.apache.kafka.common.PartitionInfo;
import org.apache.kafka.common.TopicPartition;
import org.apache.kafka.common.serialization.ByteArrayDeserializer;

import java.nio.ByteBuffer;
import java.nio.charset.CharacterCodingException;
import java.nio.charset.CodingErrorAction;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Duration;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Properties;
import java.util.Set;

/**
 * One-shot, read-only sampling for the private production mapping audit.
 * No group is joined, no offsets are committed, and no topics are created.
 * Raw messages are written only to a bounded, temporary private file inside
 * the disposable audit Pod. Stdout contains no Kafka records or rule names.
 */
public final class KafkaAuditSampler {
    private static final ObjectMapper JSON = new ObjectMapper();
    private static final int MAX_RECORD_BYTES = 256 * 1024;
    private static final long MAX_TOTAL_BYTES = 48L * 1024 * 1024;

    public static void main(String[] args) throws Exception {
        if (args.length == 1 && args[0].equals("--self-test")) {
            System.out.println("KafkaAuditSampler: ready");
            return;
        }
        if (args.length != 4) {
            throw new IllegalArgumentException("usage: KafkaAuditSampler <profiles.json> <inputs.json> <summary.json> <per-profile-limit>");
        }
        int maxSamples = Integer.parseInt(args[3]);
        if (maxSamples < 1 || maxSamples > 1000) {
            throw new IllegalArgumentException("per-profile-limit must be between 1 and 1000");
        }
        JsonNode configs = JSON.readTree(Files.readString(Path.of(args[0])));
        if (!configs.isArray() || configs.isEmpty() || configs.size() > 1000) {
            throw new IllegalArgumentException("expected 1-1000 mapping profiles");
        }
        ArrayNode inputs = JSON.createArrayNode();
        ArrayNode stats = JSON.createArrayNode();
        long totalBytes = 0;
        for (int i = 0; i < configs.size(); i++) {
            JsonNode cfg = configs.get(i);
            String bootstrap = cfg.path("brokers").asText("");
            String topic = cfg.path("topic").asText("");
            if (bootstrap.isBlank() || topic.isBlank() || !cfg.path("rules").isArray() ||
                    !cfg.path("fields").isObject() || cfg.path("output").asText("").length() != 4) {
                throw new IllegalArgumentException("invalid profile at index " + i);
            }
            ObjectNode profile = JSON.createObjectNode();
            profile.set("rules", cfg.get("rules"));
            profile.set("fields", cfg.get("fields"));
            profile.put("output", cfg.get("output").asText());
            ArrayNode docs = profile.putArray("documents");
            ObjectNode info = stats.addObject();
            info.put("profile_index", i);
            info.put("samples", 0);
            info.put("partitions", 0);
            info.put("skipped_large", 0);
            info.put("skipped_invalid_utf8", 0);
            info.put("limit_reached", false);

            Properties props = new Properties();
            props.put("bootstrap.servers", bootstrap);
            props.put("client.id", "streams-mapping-audit");
            props.put("enable.auto.commit", "false");
            props.put("allow.auto.create.topics", "false");
            props.put("key.deserializer", ByteArrayDeserializer.class.getName());
            props.put("value.deserializer", ByteArrayDeserializer.class.getName());
            props.put("request.timeout.ms", "15000");
            props.put("default.api.timeout.ms", "20000");
            props.put("max.poll.records", "200");
            props.put("max.partition.fetch.bytes", "1048576");
            try (KafkaConsumer<byte[], byte[]> consumer = new KafkaConsumer<>(props)) {
                List<PartitionInfo> partitions = consumer.partitionsFor(topic, Duration.ofSeconds(20));
                if (partitions == null || partitions.isEmpty()) {
                    throw new IllegalStateException("topic has no readable partitions at profile " + i);
                }
                List<TopicPartition> tps = new ArrayList<>();
                for (PartitionInfo partition : partitions) {
                    tps.add(new TopicPartition(topic, partition.partition()));
                }
                info.put("partitions", tps.size());
                Map<TopicPartition, Long> beginning = consumer.beginningOffsets(tps);
                Map<TopicPartition, Long> ending = consumer.endOffsets(tps);
                consumer.assign(tps);
                // Sample the tail of every partition, rather than only the
                // busiest partition. The snapshot is finite even under load.
                int perPartition = Math.max(1, (maxSamples + tps.size() - 1) / tps.size());
                Map<TopicPartition, Integer> counts = new HashMap<>();
                Set<TopicPartition> done = new HashSet<>();
                for (TopicPartition tp : tps) {
                    long end = ending.get(tp);
                    long start = Math.max(beginning.get(tp), end - perPartition);
                    consumer.seek(tp, start);
                    counts.put(tp, 0);
                    if (start >= end) {
                        done.add(tp);
                    }
                }
                if (!done.isEmpty()) consumer.pause(done);
                long deadline = System.nanoTime() + Duration.ofSeconds(40).toNanos();
                while (done.size() < tps.size() && docs.size() < maxSamples && System.nanoTime() < deadline) {
                    ConsumerRecords<byte[], byte[]> batch = consumer.poll(Duration.ofMillis(500));
                    for (ConsumerRecord<byte[], byte[]> record : batch) {
                        TopicPartition tp = new TopicPartition(record.topic(), record.partition());
                        if (done.contains(tp)) continue;
                        if (record.offset() >= ending.get(tp) || counts.get(tp) >= perPartition) {
                            done.add(tp);
                            consumer.pause(List.of(tp));
                            continue;
                        }
                        counts.put(tp, counts.get(tp) + 1);
                        byte[] value = record.value();
                        if (value != null) {
                            if (value.length > MAX_RECORD_BYTES || totalBytes + value.length > MAX_TOTAL_BYTES) {
                                info.put("skipped_large", info.get("skipped_large").asInt() + 1);
                                if (totalBytes + value.length > MAX_TOTAL_BYTES) {
                                    info.put("limit_reached", true);
                                }
                            } else {
                                try {
                                    String text = StandardCharsets.UTF_8.newDecoder()
                                            .onMalformedInput(CodingErrorAction.REPORT)
                                            .onUnmappableCharacter(CodingErrorAction.REPORT)
                                            .decode(ByteBuffer.wrap(value)).toString();
                                    docs.add(text);
                                    totalBytes += value.length;
                                } catch (CharacterCodingException e) {
                                    info.put("skipped_invalid_utf8", info.get("skipped_invalid_utf8").asInt() + 1);
                                }
                            }
                        }
                        if (counts.get(tp) >= perPartition || record.offset() + 1 >= ending.get(tp)) {
                            done.add(tp);
                            consumer.pause(List.of(tp));
                        }
                        if (docs.size() >= maxSamples) break;
                    }
                    if (totalBytes >= MAX_TOTAL_BYTES) {
                        info.put("limit_reached", true);
                        break;
                    }
                }
                if (done.size() < tps.size() && docs.size() < maxSamples && !info.get("limit_reached").asBoolean()) {
                    info.put("sampling_timed_out", true);
                }
            }
            info.put("samples", docs.size());
            inputs.add(profile);
        }
        // Parent shell sets umask 077; both files remain in the Pod's tmpfs.
        Files.writeString(Path.of(args[1]), JSON.writeValueAsString(inputs));
        ObjectNode report = JSON.createObjectNode();
        report.put("schema_version", 1);
        report.put("scope", "kafka-read-only-sampling");
        report.set("profiles", stats);
        Files.writeString(Path.of(args[2]), JSON.writeValueAsString(report));
    }
}
