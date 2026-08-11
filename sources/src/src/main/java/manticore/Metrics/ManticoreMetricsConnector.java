package manticore.Metrics;

import manticore.Worker;
import org.slf4j.Logger;

import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.SQLException;
import java.util.concurrent.TimeUnit;

public class ManticoreMetricsConnector {
    private static final int MAX_ATTEMPTS = 3;
    private int attempts = 0;
    private final int queryTimeout;
    private final String host;
    private static final Logger logger = Worker.getLogger();

    static {
        try {
            Class.forName("org.mariadb.jdbc.Driver");
        } catch (ClassNotFoundException e) {
            throw new IllegalStateException("MariaDB JDBC driver is not available", e);
        }
    }

    public ManticoreMetricsConnector(String host, int queryTimeout) {
        this.host = host;
        this.queryTimeout = queryTimeout;
    }

    public Connection getConnection() throws InterruptedException {
        attempts++;
        try {
            DriverManager.setLoginTimeout(3);
            Connection connection = DriverManager.getConnection("jdbc:mariadb://" + host
                    + "?useUnicode=true&characterEncoding=utf8&maxAllowedPacket=134217728&connectTimeout=3000", "", "");
            attempts = 0;
            logger.debug("[ManticoreMetricsConnector] Connected to Manticore metrics host: {}", host);
            return connection;
        } catch (SQLException exception) {
            if (attempts >= MAX_ATTEMPTS) {
                logger.error("[ManticoreMetricsConnector] Failed to connect to Manticore metrics host {} after {} attempts: {}",
                        host, MAX_ATTEMPTS, exception.getMessage());
                logger.trace("[ManticoreMetricsConnector] Exception during connection to Manticore metrics host:", exception);
                attempts = 0;
                return null;
            }
            logger.info("[ManticoreMetricsConnector] Retrying connection to Manticore metrics host {} (attempt {}/{})",
                    host, attempts, MAX_ATTEMPTS);
            sleep(1);
            return this.getConnection();
        }
    }

    protected void sleep(long seconds) throws InterruptedException {
        TimeUnit.SECONDS.sleep(seconds);
    }

    public int getQueryTimeout() {
        return this.queryTimeout;
    }
}
