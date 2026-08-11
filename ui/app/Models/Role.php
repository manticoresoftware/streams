<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Class Role
 *
 * @package App
 * @mixin \Eloquent
 */
class Role extends Model
{
    use LogsActivity;

    const ROLE_ADMIN = 'admin';
    const ROLE_MANAGER = 'manager';

    protected static bool $logFillable = true;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }

    /**
     * relationship
     *
     * @return HasMany
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
