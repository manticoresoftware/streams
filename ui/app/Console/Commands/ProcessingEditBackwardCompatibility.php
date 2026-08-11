<?php

namespace App\Console\Commands;

use App\Models\Processes;
use Illuminate\Console\Command;

class ProcessingEditBackwardCompatibility extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'processing:edit-backward-compatibility';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Alter existing processing record for enabling editing';


    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Exception
     */
    public function handle()
    {

        $sysTmpDir = sys_get_temp_dir();
        $processingConfigsTmpDir = $sysTmpDir.DIRECTORY_SEPARATOR.'configs';
        if (!file_exists($processingConfigsTmpDir)) {
            $this->error('Tmp dir non exists ('.$processingConfigsTmpDir.')');
            return 1;
        }

        $configNames = scandir($processingConfigsTmpDir);
        $updated = 0;
        foreach ($configNames as $configName) {
            if (in_array($configName, ['.', '..'])) {
                continue;
            }

            $config = include_once($processingConfigsTmpDir.DIRECTORY_SEPARATOR.$configName);

            $processName = $config['name'];
            $processes = Processes::where(['name' => $processName])->get();

            if ($processes->count() === 0) {
                $this->error('Process '.$processName.' don\'t found in MYSQL database');
            } else {
                foreach ($processes as $process) {
                    if (!$process) {
                        $this->error('Process '.$processName.' don\'t found in MYSQL database');
                        continue;
                    }

                    try {
                        $values = unserialize($process->values);
                    } catch (\Exception $exception) {
                        $this->error('Error until unserialization process values for '.$processName);
                        continue;
                    }

                    $userRequest = [];
                    $userRequest['attrs'] = $config['attrs'];
                    if (isset($config['jslt_conf'])) {
                        $userRequest['jslt_conf'] = urldecode($config['jslt_conf']);
                    }

                    $userRequest['output_docs'] = $config['output_docs'];

                    if (isset($config['query_complexity_validation']) && (int) $config['query_complexity_validation'] != 0) {
                        $userRequest['query_complexity_validation']['enabled'] = true;
                        $userRequest['query_complexity_validation']['max_matches_percent'] = (int) $config['query_complexity_validation'];
                    }

                    $userRequest['language'] = $config['language'];
                    $userRequest['min_threads'] = $config['min_threads'];
                    $userRequest['max_threads'] = $config['max_threads'];
                    $userRequest['max_batch_size'] = $config['max_batch_size'];

                    if (isset($config['nlp_settings'])) {
                        $userRequest['nlp_settings'] = $config['nlp_settings'];
                    }

                    if (isset($config['stopwords'])) {
                        $userRequest['stopwords'] = $config['stopwords'];
                    }

                    if (isset($config['exceptions'])) {
                        $userRequest['exceptions'] = $config['exceptions'];
                    }

                    if (isset($config['searchd_settings'])) {
                        $userRequest['searchd_settings'] = $config['searchd_settings'];
                    }

                    $values['user_request'] = $userRequest;
                    $process->values = serialize($values);
                    $process->save();
                }

                unlink($processingConfigsTmpDir.DIRECTORY_SEPARATOR.$configName);
                $this->info($configName.' was updated successfully');
                $updated++;
            }
        }

        $this->info('Job is done. Updated: '.$updated.'/'.(count($configNames) - 2));

        return 0;
    }


}
