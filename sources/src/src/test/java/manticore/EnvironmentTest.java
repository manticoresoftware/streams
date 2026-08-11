package manticore;

import ch.qos.logback.classic.Level;
import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.Timeout;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.InjectMocks;
import org.mockito.Mockito;
import org.mockito.junit.jupiter.MockitoExtension;

import java.util.ArrayList;
import java.util.List;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class EnvironmentTest {

    @InjectMocks
    private Environments environments;

    @BeforeEach
    void setUp() {
        // Use spy to allow partial mocking of Environments
        environments = spy(Environments.class);
    }

    @Test
    @Timeout(value = 2)
    void getSplittedStringReturnsDefault() {
        List<String> defaults = new ArrayList<>(List.of("default", "value"));
        when(environments.getEnv("TEST_STRING")).thenReturn(null);

        List<String> envValue = environments.get("TEST_STRING", "\\|", defaults);

        assertEquals(defaults, envValue);
    }

    @Test
    @Timeout(value = 2)
    void getSplittedStringReturnsEnv() {
        when(environments.getEnv("TEST_STRING")).thenReturn("default|default");

        List<String> envValue = environments.get("TEST_STRING", "\\|", null);

        assertEquals(List.of("default", "default"), envValue);
    }

    @Test
    @Timeout(value = 2)
    void getStringReturnsDefault() {
        when(environments.getEnv("TEST_STRING")).thenReturn(null);

        assertEquals("", environments.get("TEST_STRING", ""));
    }

    @Test
    @Timeout(value = 2)
    void getStringReturnsEnv() {
        String expectedResult = "env value";
        when(environments.getEnv("TEST_STRING")).thenReturn(expectedResult);

        assertEquals(expectedResult, environments.get("TEST_STRING", ""));
    }

    @Test
    @Timeout(value = 2)
    void getIntegerReturnsDefault() {
        when(environments.getEnv("TEST_STRING")).thenReturn(null);

        assertEquals(10, environments.get("TEST_STRING", 10));
    }

    @Test
    @Timeout(value = 2)
    void getIntegerReturnsEnv() {
        Integer expectedResult = 0;
        when(environments.getEnv("TEST_STRING")).thenReturn(expectedResult.toString());

        assertEquals(expectedResult, environments.get("TEST_STRING", 10));
    }

    @Test
    @Timeout(value = 2)
    void getLongReturnsDefault() {
        when(environments.getEnv("TEST_STRING")).thenReturn(null);

        assertEquals(10L, environments.get("TEST_STRING", 10L));
    }

    @Test
    @Timeout(value = 2)
    void getLongReturnsEnv() {
        String envValue = "1";
        Long expectedResult = 1000L;
        when(environments.getEnv("TEST_STRING")).thenReturn(envValue);
        assertEquals(expectedResult, environments.get("TEST_STRING", 10L));
    }

    @Test
    @Timeout(value = 2)
    void getBooleanReturnsDefault() {
        when(environments.getEnv("TEST_STRING")).thenReturn(null);

        assertFalse(environments.get("TEST_STRING", false));
    }

    @Test
    @Timeout(value = 2)
    void getBooleanReturnsEnv() {
        when(environments.getEnv("TEST_STRING")).thenReturn("1");

        assertTrue(environments.get("TEST_STRING", false));
    }

    @Test
    @Timeout(value = 2)
    void emptyCheck() {
        assertTrue(environments.empty(null));
        assertTrue(environments.empty(""));
        assertFalse(environments.empty("false"));
        assertFalse(environments.empty("not empty variable"));
    }

    @Test
    @Timeout(value = 2)
    void getLogLevelReturnsDefault() {
        when(environments.getEnv("TEST_STRING")).thenReturn(null);

        assertEquals(Level.WARN, environments.getLevel("TEST_STRING", Level.WARN));
    }

    @Test
    @Timeout(value = 2)
    void getLogLevelReturnsEnv() {
        when(environments.getEnv("TEST_STRING")).thenReturn(Level.DEBUG.toString());

        assertEquals(Level.DEBUG, environments.getLevel("TEST_STRING", Level.WARN));
    }
}