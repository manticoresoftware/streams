package manticore;

import ch.qos.logback.classic.Level;
import com.google.common.annotations.VisibleForTesting;

import java.util.Arrays;
import java.util.List;

public class Environments {
    public List<String> get(String name, String splitBy, List<String> defaultValue) {
        String value = getEnv(name);
        if (this.empty(value)) {
            return defaultValue;
        }
        return Arrays.asList(value.split(splitBy));
    }

    public String get(String name, String defaultValue) {
        String value = getEnv(name);
        if (this.empty(value)) {
            value = defaultValue;
        }
        return value;
    }

    public Integer get(String name, Integer defaultValue) {
        String envValue = getEnv(name);
        if (this.empty(envValue)) {
            return defaultValue;
        }
        return Integer.parseInt(envValue);
    }

    public Long get(String name, Long defaultValue) {
        String envValue = getEnv(name);
        if (this.empty(envValue)) {
            return defaultValue;
        }
        String value = Integer.parseInt(envValue) + "000";
        return Long.parseLong(value);
    }

    public boolean get(String name, boolean defaultValue) {
        String envValue = getEnv(name);
        if (this.empty(envValue)) {
            return defaultValue;
        }
        if (envValue.equals("1")) {
            return true;
        }
        return Boolean.parseBoolean(envValue);
    }

    public Boolean get(String name) {
        String envValue = getEnv(name);
        return ("1".equalsIgnoreCase(envValue)
                || "yes".equalsIgnoreCase(envValue) || "true".equalsIgnoreCase(envValue)
                || "on".equalsIgnoreCase(envValue));
    }

    public Boolean empty(String variable) {
        return (variable == null || variable.isEmpty());
    }

    public ch.qos.logback.classic.Level getLevel(String log_level, ch.qos.logback.classic.Level defaultValue) {
        String value = getEnv(log_level);
        return ch.qos.logback.classic.Level.toLevel(value, defaultValue);
    }

    @VisibleForTesting
    protected String getEnv(String variableName) {
        return System.getenv(variableName);
    }
}