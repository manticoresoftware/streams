<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Class Source
 *
 * @package App
 * @mixin \Eloquent
 */
class Source extends Model
{
    use LogsActivity, HasFactory;

    protected $table = 'sources';

    protected static bool $logFillable = true;

    protected $fillable
        = [
            'name',
            'host',
            'topic',
            'group',
            'banned',
        ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }
}
