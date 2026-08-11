<?php

class Scaler
{
    const SCALE_DELAY = 300;

    private $lag;
    private $lastQueryProcessingTime = null;
    private $docsPerSecond = 1;
    private $threads = 1;
    private $batchSize = 0;
    private $label;

    private $maxBatchSize;
    private $minThreads = 1;
    private $maxThreads = 1;
    private $maxQueryProcessingTime;

    private Response $response;
    private Curl $curl;

    private mysqli $connection;
    private string $namespace = '';
    /**
     * @var array|false|string
     */
    private $releaseName;

    public function __construct()
    {
        $this->response = new Response();

        /* activate reporting */
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $this->connection = @new mysqli(getenv("METRICS_STORAGE_URL"), '', '', '');
            $this->connection->set_charset("utf8");
        } catch (Throwable $exception) {
            $this->response->error = $exception->getMessage();
            $this->response->printResponse(500);
            die();
        }

        if (file_exists('/var/run/secrets/kubernetes.io/serviceaccount/namespace')) {
            $this->namespace = trim(file_get_contents('/var/run/secrets/kubernetes.io/serviceaccount/namespace'));
        }

        $this->maxQueryProcessingTime = getenv("MAX_QUERY_PROCESSING_TIME");
        $this->releaseName            = getenv("RELEASE_NAME");
        $this->curl                   = new Curl();


        foreach (
            [
                'minThreads',
                'maxThreads',
                'lag',
                'label',
                'lastQueryProcessingTime',
                'batchSize',
                'maxBatchSize',
            ] as $k
        ) {
            if (isset($_POST[$k])) {
                $this->$k = $_POST[$k];
            }
        }

        $this->threads = $this->getThreads();

        $this->docsPerSecond = $this->getWorkerProcessing();

        if ($this->batchSize === 0) {
            $this->batchSize = 1;
        }
    }

    public function getWorkerProcessing()
    {
        $time = time() - 15;
        $query
              = "select value as processed from metrics where match('@metric_name {$this->label}_worker_processed_per_sec') and scrapTime > ".$time." GROUP BY tag";


        $result = $this->connection->query($query);
        if ($result) {
            $result     = $result->fetch_all(MYSQLI_ASSOC);
            $processed = 0;
            foreach ($result as $worker) {
                $processed += (int)$worker['processed'];
            }

            return $processed;
        }


        return 0;
    }

    public function check(): void
    {
        if ($this->lag !== null) {
            if ((int)$this->lag !== 0 && $this->docsPerSecond !== 0) {
                $lagProcessingTime = $this->lag / $this->docsPerSecond;
            } elseif ((int)$this->lag !== 0 && $this->docsPerSecond === 0) {
                $lagProcessingTime = 11;
            } else {
                $lagProcessingTime = 0;
            }


            $this->response->lagProcessingTime = $lagProcessingTime;
            $this->response->lag               = $this->lag;
            $this->response->docsPerSecond     = $this->docsPerSecond;


            if ( ! empty($this->lastQueryProcessingTime) && $this->lastQueryProcessingTime >= $this->maxQueryProcessingTime) {
                $this->scaleUp();
                $this->response->reason = "Scale by latency";
            } elseif ( ! empty($this->batchSize) && ! empty($this->maxBatchSize) && $this->batchSize >= $this->maxBatchSize
                && $lagProcessingTime > 10
            ) {
                $this->scaleUp();
                $this->response->reason = "Scale cause max batch";
            } elseif ($lagProcessingTime > 10) {
                if ($this->threads > 1) {
                    $this->scaleUp();
                }
                $this->increaseBatch();
                $this->response->reason = "Inc batch cause lag processing > 10";
            } elseif ($lagProcessingTime < 10) {
                if ($this->threads == 1) {
                    $this->decreaseBatch();
                    $this->response->reason = "Dec batch cause lag processing < 10";
                } else {
                    $this->scaleDown();
                    $this->response->reason = "Scale down cause lag processing < 10";
                }
            }
        }


        $this->response->printResponse();
    }

    private function increaseBatch(): void
    {
        $this->batchSize = ceil($this->batchSize *= 1.5);
        if ( ! empty($this->maxBatchSize) && $this->batchSize > $this->maxBatchSize) {
            $this->batchSize = $this->maxBatchSize;
        }
        $this->response->batchSize = $this->batchSize;
    }

    private function decreaseBatch(): void
    {
        $this->batchSize = ceil($this->batchSize /= 1.5);

        $minBatch = $this->maxBatchSize * 0.05;
        if ($this->batchSize < $minBatch) {
            $this->batchSize = $minBatch;
        }
        $this->response->batchSize = $this->batchSize;
    }

    private function scaleUp(): void
    {
        $this->threads += 1;
        if ($this->threads > $this->maxThreads) {
            $this->threads = $this->maxThreads;

            return;
        }
        $this->scale();
    }

    private function scaleDown(): void
    {
        $this->threads -= 1;
        if ($this->threads < $this->minThreads) {
            $this->threads = $this->minThreads;

            return;
        }
        $this->scale();
    }

    private function scale()
    {
        $skipScaling = getenv("SKIP_SCALING");
        if ( ! empty($skipScaling) && (int)$skipScaling === 1) {
            $message = 'SKIP_SCALING flag passed. Skipping scaling-related calculations for pipeline '.$this->label;
            $this->log($message);
            $this->response->message = $message;
            $this->response->printResponse();

            return;
        }


        $canScaleFrom = $this->getNextScaling();
        if ($canScaleFrom > time()) {
            $this->log('Skipping scaling, because the scale delay is not exceeded yet');

            return;
        }

        $this->response->threads = $this->threads;

        $host
                 = "https://kubernetes.default.svc/apis/apps/v1/namespaces/$this->namespace/statefulsets/$this->releaseName-$this->label-pipeline/scale";
        $content = [
            [
                "op"    => "replace",
                "path"  => "/spec/replicas",
                "value" => (int)$this->threads,
            ],
        ];

        $this->log('Start scaling '.$host.' '.json_encode($content));

        $request = $this->curl->replaceRequest($host, json_encode($content));
        $this->log('Scaling request '.json_encode($request));
        $this->log('Scaling error '.json_encode($this->curl->getErrors()));
        $this->setNextScaling();
    }

    private function getThreads(): int
    {
        $host
                 = "https://kubernetes.default.svc/apis/apps/v1/namespaces/$this->namespace/statefulsets/$this->releaseName-$this->label-pipeline/scale";
        $request = $this->curl->get($host);
        if (isset($request['result']['status']['replicas'])) {
            return (int)$request['result']['status']['replicas'];
        }

        return 1;
    }

    private function setNextScaling()
    {
        file_put_contents(
            DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.$this->label.'.dat',
            time() + self::SCALE_DELAY
        );
    }

    private function getNextScaling()
    {
        $file = DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.$this->label.'.dat';
        if ( ! file_exists($file)) {
            return time();
        }

        return (int)file_get_contents($file);
    }


    function log($message)
    {
        $fp = fopen('php://stdout', 'wb');
        fwrite($fp, "$message\n");
        fclose($fp);
    }
}
