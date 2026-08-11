<?php

namespace App\Console\Commands;

use App\Models\Streams;
use App\Services\Curl\CurlService;
use App\Services\Curl\KubeService;
use App\Services\StreamsService;
use Illuminate\Console\Command;

class ScrapMetrics extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metrics:scrap';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrap Prometheus metrics cache';


    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Exception
     */
    public function handle(KubeService $curlService)
    {

        return false;
    }


}
