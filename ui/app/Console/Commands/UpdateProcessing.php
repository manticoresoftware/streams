<?php

namespace App\Console\Commands;

use App\Models\Processes;
use App\Services\Curl\KubeService;
use App\Services\StreamsService;
use Illuminate\Console\Command;

class UpdateProcessing extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process:update {process_id} {force?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upgrade processing pipelines';


    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Exception
     */
    public function handle(KubeService $curlService)
    {
        $force = $this->argument('force') ?? false;

        $process = Processes::find($this->argument('process_id'));
        $pipelines = $process->streams()->get();


        foreach ($pipelines as $pipeline){

            try {

                 $values = unserialize($process->values);

                 // Merge kafka_config from user_request into main config for StreamsService
                 if (isset($values['user_request']['kafka_config']) && is_array($values['user_request']['kafka_config'])) {
                     $kafkaConfig = $values['user_request']['kafka_config'];

                     // Map the kafka_config values to the expected structure for StreamsService
                     // Use underscores instead of dots to work with strToValue method
                     $values['kafka']['fetch_min_bytes'] = $kafkaConfig['fetch.min.bytes'] ?? $values['kafka']['fetch_min_bytes'];
                     $values['kafka']['fetch_max_wait_ms'] = $kafkaConfig['fetch.max.wait.ms'] ?? $values['kafka']['fetch_max_wait_ms'];
                     $values['kafka']['fetch_max_bytes'] = $kafkaConfig['fetch.max.bytes'] ?? $values['kafka']['fetch_max_bytes'];
                     $values['kafka']['max_poll_records'] = $kafkaConfig['max.poll.records'] ?? $values['kafka']['max_poll_records'];
                 }

                 if (strpos($values['kafka']['inputTopic'], "{username}") !== false) {
                    $values['kafka']['inputTopic'] = str_replace("{username}", $pipeline->user->name,
                                                                 $values['kafka']['inputTopic']);
                }

                if (strpos($values['kafka']['outputTopic'], "{username}") !== false) {
                    $values['kafka']['outputTopic'] = str_replace("{username}", $pipeline->user->name,
                        $values['kafka']['outputTopic']);
                }

                if (strpos($values['kafka']['groupName'], "{username}") !== false) {
                    $values['kafka']['groupName'] = str_replace("{username}", $pipeline->user->name,
                        $values['kafka']['groupName']);
                }

                $streamService  = new StreamsService($curlService, $pipeline, $values);
                $streamService->setProcessId($process->id);
                $streamService->upgradeStream();
                if ($force){
                    $streamService->redeployPipeline();
                }

            } catch (\Exception $e) {
                $serviceErrors = [];
                if (isset($streamService)){
                    $serviceErrors = $streamService->getErrors();
                }
                $this->error(implode('Uncaught exception <br>',
                    array_merge([$e->getMessage()], $serviceErrors)));
            }
        }


        $this->info("Success");

        return false;
    }


}
