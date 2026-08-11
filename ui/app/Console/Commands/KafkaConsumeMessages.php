<?php

namespace App\Console\Commands;

use App\Services\KafkaConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class KafkaConsumeMessages extends Command
{

    const RUNNING_TIME = 52;
    const RESULTS_FILENAME = 'receivedDataFromKafka.dat';

    private $messages = [];

    private $memoryLimit = 0;
    private $messageArrayLength = 0;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kafka:consume {host} {group} {topic}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reads Kafka topic during one minute';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        Storage::delete(self::RESULTS_FILENAME);
        $this->getMemLimit();
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @param KafkaConnection $kafkaConnection
     *
     * @return mixed
     * @throws \Exception
     */
    public function handle(KafkaConnection $kafkaConnection)
    {
        $start = time();

        $kafkaConnection->connect($this->argument('host'), $this->argument('topic'), $this->argument('group'));

        while (true) {
            if ($start + self::RUNNING_TIME < time()) {
                $this->saveData();
                return true;
            }
            $message = $kafkaConnection->consume(10 * 1000);
            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $this->messageArrayLength += mb_strlen($message->payload);
                    //\Log::debug($this->messageArrayLength . ' < ' . $this->memoryLimit);
                    if ($this->messageArrayLength >= $this->memoryLimit) {
                        //    \Log::debug($this->messageArrayLength . ' > ' . $this->memoryLimit);
                        //    \Log::debug("Start saving data");
                        $this->saveData();

                        return true;
                    }
                    $this->messages[] = $message->payload;

                    break;
                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    break;
                default:
                    throw new \Exception($message->errstr(), $message->err);
                    break;
            }
        }

        return false;
    }

    private function saveData()
    {
        if ( ! empty($this->messages)) {

            $jsonAnalyzer = new \App\Services\JsonAnalyzer($this->messages);
            Storage::put(self::RESULTS_FILENAME, json_encode($jsonAnalyzer->analyze()));
        }
        // \Log::debug("Saved");
    }


    private function getMemLimit()
    {
        $memoryLimit = ini_get('memory_limit');

        if ($memoryLimit == -1) {
            $memoryLimit = "128M";
        }
        if (preg_match('/^(\d+)(.)$/', $memoryLimit, $matches)) {
            if ($matches[2] == 'M') {
                $memoryLimit = $matches[1] * 1024 * 1024; // nnnM -> nnn MB
            } else {
                if ($matches[2] == 'K') {
                    $memoryLimit = $matches[1] * 1024; // nnnK -> nnn KB
                }
            }
        }

        $this->memoryLimit = $memoryLimit * 0.1;
    }
}
