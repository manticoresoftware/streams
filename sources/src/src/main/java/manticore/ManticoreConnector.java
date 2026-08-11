package manticore;

import kong.unirest.HttpResponse;
import kong.unirest.Unirest;
import org.json.JSONArray;
import org.json.JSONObject;
import org.json.JSONTokener;
import org.slf4j.Logger;

import java.util.concurrent.ExecutionException;
import java.util.concurrent.Future;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.TimeoutException;

public class ManticoreConnector {
    private static final int MAX_ATTEMPTS = 3;
    private static final String JSON_RESPONSE_TYPE = "application/json";
    private static final String CLI_CONTENT_TYPE = "text/plain";
    private static final String BULK_CONTENT_TYPE = "application/x-ndjson";

    private final int queryTimeout;
    private final String manticoreHost;
    private final int manticoreHttpPort;
    private static final Logger logger = Worker.getLogger();

    public ManticoreConnector(String host, int httpPort, int queryTimeout) {
        this.manticoreHost = host;
        this.manticoreHttpPort = httpPort;
        this.queryTimeout = queryTimeout;
    }

    public ManticoreCliResult executeCli(String query) throws InterruptedException {
        for (int attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
            try {
                ManticoreCliResult result = parseCliResponse(postCli(query));
                logger.debug("[ManticoreConnector] Executed HTTP query against host: {}", manticoreHost);
                return result;
            } catch (Exception exception) {
                if (attempt == MAX_ATTEMPTS) {
                    logger.error("[ManticoreConnector] Failed HTTP query to Manticore host {} after {} attempts: {}",
                            manticoreHost, MAX_ATTEMPTS, exception.getMessage());
                    logger.trace("[ManticoreConnector] Exception during HTTP query to Manticore:", exception);
                    throw exception;
                }
                logger.info("[ManticoreConnector] Retrying HTTP query to Manticore host {} (attempt {}/{})",
                        manticoreHost, attempt, MAX_ATTEMPTS);
                sleep(1);
            }
        }
        throw new IllegalStateException("Unreachable retry state");
    }

    public void executeBulk(String payload) throws InterruptedException {
        for (int attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
            try {
                HttpResponse<String> response = postBulk(payload);
                validateBulkResponse(response);
                logger.debug("[ManticoreConnector] Executed HTTP bulk request against host: {}", manticoreHost);
                return;
            } catch (Exception exception) {
                if (attempt == MAX_ATTEMPTS) {
                    logger.error("[ManticoreConnector] Failed HTTP bulk request to Manticore host {} after {} attempts: {}",
                            manticoreHost, MAX_ATTEMPTS, exception.getMessage());
                    logger.trace("[ManticoreConnector] Exception during HTTP bulk request to Manticore:", exception);
                    throw exception;
                }
                logger.info("[ManticoreConnector] Retrying HTTP bulk request to Manticore host {} (attempt {}/{})",
                        manticoreHost, attempt, MAX_ATTEMPTS);
                sleep(1);
            }
        }
    }

    public static ManticoreCliResult parseCliResponse(HttpResponse<String> response) {
        return new ManticoreCliResult(parseResultSets(response));
    }

    static JSONArray parseResultSets(HttpResponse<String> response) {
        if (response.getStatus() >= 400) {
            throw new RuntimeException(response.getBody());
        }

        String body = response.getBody();
        if (body == null || body.isEmpty()) {
            return new JSONArray();
        }

        Object parsed = new JSONTokener(body).nextValue();
        if (parsed instanceof JSONArray jsonArray) {
            return jsonArray;
        }
        if (parsed instanceof JSONObject jsonObject) {
            return new JSONArray().put(jsonObject);
        }
        throw new RuntimeException("Unexpected response from Manticore HTTP API");
    }

    protected void sleep(long seconds) throws InterruptedException {
        TimeUnit.SECONDS.sleep(seconds);
    }

    public int getQueryTimeout() {
        return this.queryTimeout;
    }

    public String getManticoreHost() {
        return manticoreHost;
    }

    protected HttpResponse<String> post(String endpoint, String contentType, String body) {
        Future<HttpResponse<String>> request = startRequest(endpoint, contentType, body);
        try {
            return awaitResponse(request);
        } catch (InterruptedException e) {
            request.cancel(true);
            Thread.currentThread().interrupt();
            throw new RuntimeException("Interrupted while waiting for Manticore HTTP response", e);
        } catch (TimeoutException e) {
            request.cancel(true);
            throw new RuntimeException("Manticore HTTP query timed out after " + queryTimeout + " seconds", e);
        } catch (ExecutionException e) {
            throw new RuntimeException(e.getCause() == null ? e.getMessage() : e.getCause().getMessage(), e);
        }
    }

    protected Future<HttpResponse<String>> startRequest(String endpoint, String contentType, String body) {
        return Unirest.post(endpoint)
                .header("Content-Type", contentType)
                .header("Accept", JSON_RESPONSE_TYPE)
                .body(body)
                .asStringAsync();
    }

    protected HttpResponse<String> awaitResponse(Future<HttpResponse<String>> request)
            throws InterruptedException, TimeoutException, ExecutionException {
        return request.get(queryTimeout, TimeUnit.SECONDS);
    }

    protected HttpResponse<String> postCli(String query) {
        return post(buildCliEndpoint(manticoreHost, manticoreHttpPort), CLI_CONTENT_TYPE, query);
    }

    protected HttpResponse<String> postBulk(String payload) {
        return post(buildBulkEndpoint(manticoreHost, manticoreHttpPort), BULK_CONTENT_TYPE, payload);
    }

    private void validateBulkResponse(HttpResponse<String> response) {
        if (response.getStatus() >= 400) {
            throw new RuntimeException(response.getBody());
        }

        String body = response.getBody();
        if (body == null || body.isEmpty()) {
            return;
        }

        JSONObject json = new JSONObject(body);
        if (json.optBoolean("errors", false)) {
            throw new RuntimeException(body);
        }
    }

    public static String buildCliEndpoint(String mysqlHost, int httpPort) {
        return buildHttpEndpoint(mysqlHost, httpPort, "/cli_json");
    }

    public static String buildBulkEndpoint(String mysqlHost, int httpPort) {
        return buildHttpEndpoint(mysqlHost, httpPort, "/bulk");
    }

    private static String buildHttpEndpoint(String mysqlHost, int httpPort, String path) {
        String hostPart = mysqlHost;
        int colonIndex = mysqlHost.lastIndexOf(':');
        if (colonIndex > -1 && colonIndex < mysqlHost.length() - 1) {
            hostPart = mysqlHost.substring(0, colonIndex);
        }
        return "http://" + hostPart + ":" + httpPort + path;
    }
}
