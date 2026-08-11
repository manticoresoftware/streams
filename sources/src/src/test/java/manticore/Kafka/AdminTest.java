package manticore.Kafka;

import org.apache.kafka.clients.admin.AdminClient;
import org.apache.kafka.clients.admin.ListTopicsResult;
import org.apache.kafka.clients.admin.NewTopic;
import org.apache.kafka.common.KafkaFuture;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.ArgumentCaptor;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;

import java.util.Collections;
import java.util.HashSet;
import java.util.List;
import java.util.Set;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class AdminTest {

    @Mock
    private AdminClient mockAdminClient;

    private Admin admin;

    @BeforeEach
    void setUp() {
        admin = new Admin(mockAdminClient); // Use the testing constructor
    }

    @Test
    void testIsTopicExists_TopicExists() throws Exception {
        ListTopicsResult mockListTopicsResult = mock(ListTopicsResult.class);
        when(mockAdminClient.listTopics()).thenReturn(mockListTopicsResult);

        KafkaFuture<Set<String>> kafkaFuture = mock(KafkaFuture.class);
        Set<String> topics = new HashSet<>(Collections.singletonList("existing-topic"));
        when(kafkaFuture.get()).thenReturn(topics);
        when(mockListTopicsResult.names()).thenReturn(kafkaFuture);

        Boolean result = admin.isTopicExists("existing-topic");
        assertTrue(result, "The topic should exist");
    }

    @Test
    void testIsTopicExists_TopicDoesNotExist() throws Exception {
        ListTopicsResult mockListTopicsResult = mock(ListTopicsResult.class);
        when(mockAdminClient.listTopics()).thenReturn(mockListTopicsResult);

        KafkaFuture<Set<String>> kafkaFuture = mock(KafkaFuture.class);
        Set<String> topics = new HashSet<>();
        when(kafkaFuture.get()).thenReturn(topics);
        when(mockListTopicsResult.names()).thenReturn(kafkaFuture);

        Boolean result = admin.isTopicExists("non-existent-topic");
        assertFalse(result, "The topic should not exist");
    }

    @Test
    void testIsTopicExists_ThrowsExceptionOnFailure() {
        ListTopicsResult mockListTopicsResult = mock(ListTopicsResult.class);
        when(mockAdminClient.listTopics()).thenReturn(mockListTopicsResult);

        when(mockListTopicsResult.names()).thenThrow(new RuntimeException("Kafka error"));

        Exception exception = assertThrows(Exception.class, () -> admin.isTopicExists("any-topic"));
        assertEquals("[KafkaAdmin] Failed to check existence of topic any-topic: Kafka error", exception.getMessage());
    }

    @Test
    void testCreateTopic_NewTopicCreated() throws Exception {
        Admin spyAdmin = spy(admin);
        doReturn(false).when(spyAdmin).isTopicExists("new-topic");

        ArgumentCaptor<List<NewTopic>> captor = ArgumentCaptor.forClass(List.class);
        spyAdmin.createTopic("new-topic");

        verify(mockAdminClient).createTopics(captor.capture());

        List<NewTopic> createdTopics = captor.getValue();
        assertEquals(1, createdTopics.size(), "Exactly one topic should be created");
        assertEquals("new-topic", createdTopics.get(0).name(), "The new topic should have the correct name");
        assertEquals(4, createdTopics.get(0).numPartitions(), "The new topic should have 4 partitions");
        assertEquals(1, createdTopics.get(0).replicationFactor(), "The new topic should have a replication factor of 1");
    }
}