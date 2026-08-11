<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Class Destination
 *
 * @package App
 * @mixin \Eloquent
 */
class Destination extends Model
{
    use LogsActivity, HasFactory;

    protected static bool $logFillable = true;

    protected $table = 'destinations';

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
