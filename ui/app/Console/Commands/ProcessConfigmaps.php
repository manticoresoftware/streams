<?php

namespace App\Console\Commands;

use App\Models\Processes;
use App\Providers\ProcessCreationService;
use App\Services\Curl\KubeService;
use App\Services\StreamsService;
use Illuminate\Console\Command;

class ProcessConfigmaps extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process:configmap {process_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update processing configmap';


    private StreamsService $streamsService;

    private ProcessCreationService $processCreationService;

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Exception
     */
    public function handle(StreamsService $streamsService, ProcessCreationService $processCreationService)
    {
        $this->streamsService = $streamsService;
        $this->processCreationService = $processCreationService;
        $processId = $this->argument('process_id');
        if ($processId) {
            $this->updateConfigMap(Processes::findOrFail($this->argument('process_id')));
        } else {
            $processes = Processes::all();
            foreach ($processes as $process) {
                $this->updateConfigMap($process);
            }
        }


        return false;
    }

    /**
     * @throws \Exception
     */
    private function updateConfigMap(Processes $process): void
    {
        $process = $process->toArray();
        $values = unserialize($process['values']);

        if (empty($values['user_request'])) {
            return;
        }

        $config = $values['user_request'];
        $language = $config['language'];
        $language = explode(',', $language);

        $nlpSettings = '';
        $stopWords = '';
        $exceptions = '';

        if (isset($config['nlp_settings'])) {
            $nlpSettings = $config['nlp_settings'];
        }

        if (isset($config['stopwords'])) {
            $stopWords = $config['stopwords'];
        }

        if (isset($config['exceptions'])) {
            $exceptions = $config['exceptions'];
        }


        if ($language === ['custom']) {
            if ($stopWords) {
                $nlpSettings = $this->processCreationService->formatStopWords($nlpSettings);
            }

            if ($exceptions) {
                $nlpSettings = $this->processCreationService->formatExceptions($nlpSettings);
            }
        }


        if (empty($nlpSettings)) {
            $morphology = config('morphology');
            $morphology = $this->processCreationService->formatMorphology($language, $morphology);
            $nlpSettings = implode(" ", $morphology);;
        }


        $this->streamsService->setProcessId($process['id']);
        $this->streamsService->createConfigmap($nlpSettings, '', $stopWords, $exceptions);
        $errors = $this->streamsService->getErrors();
        if ($errors !== []) {
            $this->error(
                'Processing '.$process['name'].' (#'.$process['id'].') configmap creation error:'.implode("\n", $errors)
            );
            return;
        }

        $this->info('Configmap for processing '.$process['name'].' (#'.$process['id'].') created successfully');
    }


}
