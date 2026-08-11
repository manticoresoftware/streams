<?php

namespace App\Console\Commands\Activity;

use App\Models\Rule;
use App\Models\User;
use App\Services\FileCacheService;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

abstract class ActivityCache extends Command
{
    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Exception
     */
    public function handle()
    {
        $stats = FileCacheService::getAll($this->getType());

        foreach ($stats as $user => $count) {
            $this->fillActivity($this->getActionName().' '.$count.' rules', $user);
        }

        FileCacheService::release($this->getType());

        return false;
    }

    protected function fillActivity($description, $user): void
    {
        $activity               = new Activity();
        $activity->log_name     = 'default';
        $activity->description  = $description;
        $activity->subject_type = Rule::class;
        $activity->subject_id   = null;
        $activity->causer_type  = User::class;
        $activity->causer_id    = $user;
        $activity->properties   = [];
        $activity->save();
    }

    abstract protected function getType(): string;

    abstract protected function getActionName(): string;
}
