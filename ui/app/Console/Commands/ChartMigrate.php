<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChartMigrate extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chart:migrate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run migrations or seed if empty schema';


    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Exception
     */
    public function handle()
    {
        while (true) {
            try {
                DB::connection()->getPdo();
                break;
            } catch (\Exception $e) {
                $this->error("Could not connect to the database. Error:".$e);
            }
            sleep(1);
        }

        Log::error("MySQL connection success");

        $migrationType = 'M';
        while (true) {
            $tables = DB::select('SHOW TABLES');

            if ($tables === []) {
                $exitCode = Artisan::call('migrate:fresh', [
                    '--force' => true, '--seed' => true,
                ]);

                $migrationType = 'Full M';
            } else {
                $exitCode = Artisan::call('migrate', [
                    '--force' => true,
                ]);
            }

            if ($exitCode === 0) {
                break;
            }
        }

        $this->error($migrationType."igration success");

        return false;
    }
}
