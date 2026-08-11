<?php

namespace App\Console\Commands\Activity;

use App\Services\FileCacheService;


class RuleInsertCache extends ActivityCache
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activity:cache-insert';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Save inserted rules stats from cache';

    protected function getType(): string
    {
        return FileCacheService::RULE_ADD;
    }

    protected function getActionName(): string
    {
        return 'Inserted';
    }
}
