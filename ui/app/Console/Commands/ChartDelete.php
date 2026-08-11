<?php

namespace App\Console\Commands;

use App\Models\Streams;
use App\Services\Curl\CurlService;
use App\Services\Curl\KubeService;
use App\Services\StreamsService;
use Illuminate\Console\Command;

class ChartDelete extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chart:delete';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove dynamically created stream resources';


    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Exception
     */
    public function handle(KubeService $curlService)
    {
        $streams = Streams::all();

        foreach ($streams as $stream) {
            $process = $stream->process;
            $user    = $stream->user;

            try {
                $values = unserialize($process->values);

                if (strpos($values['kafka']['inputTopic'], "{username}") !== false) {
                    $values['kafka']['inputTopic'] = str_replace("{username}", $user->name,
                                                                 $values['kafka']['inputTopic']);
                }

                if (strpos($values['kafka']['outputTopic'], "{username}") !== false) {
                    $values['kafka']['outputTopic'] = str_replace("{username}", $user->name,
                        $values['kafka']['outputTopic']);
                }

                if (strpos($values['kafka']['groupName'], "{username}") !== false) {
                    $values['kafka']['groupName'] = str_replace("{username}", $user->name,
                        $values['kafka']['groupName']);
                }

                $streamService  = new StreamsService($curlService, $stream, $values);
                $streamService->removeStream();
            } catch (\Exception $e) {
                echo implode('Uncaught exception <br>',
                    array_merge([$e->getMessage()], $streamService->getErrors()));
            }
        }

        $this->info("Success");
        return false;
    }


}
