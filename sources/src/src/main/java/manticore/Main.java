package manticore;

import io.prometheus.client.exporter.HTTPServer;
import manticore.Kafka.Admin;
import manticore.Kafka.Consumer;
import manticore.Kafka.Producer;
import manticore.Metrics.ManticoreColumnar;
import manticore.Metrics.MetricsStorage;

import java.io.IOException;

public class Main {
    public static void main(String[] args) throws Exception {
        try {
            new HTTPServer.Builder()
                    .withPort(8081)
                    .build();
            Worker.getLogger().info("[Main] Started Prometheus web server on port 8081");
        } catch (IOException exception) {
            System.out.print("Exception: IOException at start Prometheus web server state. " + exception);
        }

        LoggerProvider loggerProvider = new LoggerProvider();
        WorkerConfig config = new WorkerConfig(loggerProvider);
        ManticoreConnector manticoreConnector = new ManticoreConnector(
                config.getManticoreHost(),
                config.getManticoreHttpPort(),
                config.getManticoreQueryTimeout()
        );
        Producer producer = new Producer(config);
        Consumer consumer = new Consumer(config);
        Admin admin = new Admin(config.getInputHost());
        MetricsStorage storage = new ManticoreColumnar(config);
        JsonTransformer jsonTransformer = new JsonTransformer(config);
        JsltTransformer jsltTransformer = !config.getJsltConfig().trim().isEmpty() ? new JsltTransformer(config.getJsltConfig()) : null;
        ManualGarbageCleaner garbageCleaner = new ManualGarbageCleaner();
        ScalingMetrics scalingMetrics = new ScalingMetrics(config);
        RuntimeMetrics runtimeMetrics = new RuntimeMetrics(config.getProcessLabel(), storage);
        ManticoreBatch manticoreBatch = new ManticoreBatch(manticoreConnector, config.getBatchSize(),
                "1".equals(String.valueOf(config.getOutputDocs().charAt(3))), jsonTransformer, jsltTransformer,
                producer, garbageCleaner, scalingMetrics, runtimeMetrics);

        scalingMetrics.setMaxBatchSize(config.getMaxBatchSize());
        scalingMetrics.setBatchSize(config.getBatchSize());
        scalingMetrics.setMinThreadsCount(config.getMinThreads());
        scalingMetrics.setMaxThreadsCount(config.getMaxThreads());

        Worker worker = new Worker(config, manticoreConnector, consumer, admin,
                manticoreBatch, garbageCleaner, scalingMetrics, runtimeMetrics, loggerProvider);

        worker.init(config);
        Worker.getLogger().info("[Main] Worker started successfully for process {} on topic {}", config.getProcessLabel(), config.getInputTopic());
    }
}
