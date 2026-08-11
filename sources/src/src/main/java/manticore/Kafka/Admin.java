package manticore.Kafka;

import java.util.*;

import org.apache.kafka.clients.admin.AdminClient;
import org.apache.kafka.clients.admin.AdminClientConfig;
import org.apache.kafka.clients.admin.ListTopicsResult;
import org.apache.kafka.clients.admin.NewTopic;

import ch.qos.logback.classic.Logger;
import manticore.Worker;

public class Admin {
    private final AdminClient admin;

    public Admin(AdminClient adminClient) {
        this.admin = adminClient;
    }

    public Admin(String host) {
        Properties props = new Properties();
        props.put(AdminClientConfig.BOOTSTRAP_SERVERS_CONFIG, host);
        props.put("connections.max.idle.ms", 5000);
        props.put("request.timeout.ms", 120000);
        props.put("retries", 5);

        admin = AdminClient.create(props);
    }

    protected Boolean isTopicExists(String topic) throws Exception {
        ListTopicsResult listTopics = admin.listTopics();
        try {
            Set<String> names = listTopics.names().get();
            return names.contains(topic);
        } catch (Exception e1) {
            throw new Exception("[KafkaAdmin] Failed to check existence of topic " + topic + ": " + e1.getMessage());
        }
    }

    public void createTopic(String topic) throws Exception {
        if (!this.isTopicExists(topic)) {
            Logger logger = Worker.getLogger();
            logger.info("[KafkaAdmin] Creating topic: {}", topic);

            List<NewTopic> topicList = new ArrayList<>();
            Map<String, String> configs = new HashMap<>();
            int partitions = 4;
            short replication = 1;
            NewTopic newTopic = new NewTopic(topic, partitions, replication).configs(configs);
            topicList.add(newTopic);
            admin.createTopics(topicList);
        }
    }
}