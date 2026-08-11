<?php

namespace App\Console\Commands;

use App\Models\Streams;
use App\Services\Curl\CurlService;
use App\Services\Curl\KubeService;
use App\Services\StreamsService;
use Illuminate\Console\Command;

class DetectOrphans extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orphans:detect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check is orphan pipelines exist';


    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Exception
     */
    public function handle(KubeService $kubeService)
    {
        $podNames = [];

        $url       = env('K8S_URL',
            'https://kubernetes.default.svc');
        $namespace = env('NAMESPACE', 'manticore-streams');

        $pods = $kubeService->get($url . "/api/v1/namespaces/$namespace/pods?labelSelector=app.kubernetes.io/component=worker");

        $streams = Streams::all()->pluck('id')->toArray();

        if (isset($pods['result']['items'])) {
            foreach ($pods['result']['items'] as $pod) {
                preg_match('/^(.*?-m)(\d*)(-pipeline)-\d*$/usi', $pod['metadata']['name'], $matches);

                $name            = $matches[1] . $matches[2] . $matches[3];
                $podNames[$name] = (int)$matches[2];
            }
        }


        $errors = 0;
        foreach ($podNames as $fullName => $index) {
            if (!in_array($index, $streams)) {
                $this->error("Pod $fullName doesn't has stream");
                $errors++;
            }
        }


        $podNames = array_flip($podNames);
        foreach ($streams as $index) {
            if (!isset($podNames[$index])) {
                $this->error("Pipeline $index doesn't has pods");
                $errors++;
            }
        }


        if ($errors === 0) {
            $this->info("No errors found");
        }

        return 0;
    }


}
