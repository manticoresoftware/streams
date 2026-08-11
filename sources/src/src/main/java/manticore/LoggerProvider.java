package manticore;

import org.slf4j.LoggerFactory;
import ch.qos.logback.classic.Logger;

public class LoggerProvider {
    public Logger getLogger() {
        return (Logger) LoggerFactory.getLogger(Logger.ROOT_LOGGER_NAME);
    }
}