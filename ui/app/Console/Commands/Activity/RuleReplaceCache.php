<?php

namespace App\Console\Commands\Activity;

use App\Services\FileCacheService;


class RuleReplaceCache extends ActivityCache
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activity:cache-replace';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Save replaced rules stats from cache';

    protected function getType(): string
    {
        return FileCacheService::RULE_REPLACE;
    }

    protected function getActionName(): string
    {
        return 'Replaced';
    }
}
