<?php

namespace App\Console\Commands\Activity;

use App\Services\FileCacheService;


class RuleDeleteCache extends ActivityCache
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activity:cache-delete';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Save deleted rules stats from cache';

    protected function getType(): string
    {
        return FileCacheService::RULE_DELETE;
    }

    protected function getActionName(): string
    {
        return 'Deleted';
    }
}
