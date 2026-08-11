<?php

namespace App\Console\Commands;

use App\Models\Streams;
use App\Services\Curl\CurlService;
use App\Services\Curl\KubeService;
use App\Services\StreamsService;
use Illuminate\Console\Command;

class ConfigmapsCreate extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'configmaps:create';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Once will create configmaps for backward compatibility';


    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Exception
     */
    public function handle()
    {
        $processes = \App\Models\Processes::all();

        foreach ($processes as $process) {
            /** @var StreamsService $streamsService */
            $streamsService = app(StreamsService::class);
            $streamsService->setProcessId($process->id);

            $streamsService->createConfigmap("");

            $errors = $streamsService->getErrors();
            if ($errors !== []) {
                $this->info("Processing #".$process->id." configmap creation error: ".implode("\n", $errors));
            }
        }

        $this->info("Finish");

        return false;
    }


}
