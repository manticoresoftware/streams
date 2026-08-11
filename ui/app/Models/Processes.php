<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Class Processes
 *
 * @package App
 * @mixin \Eloquent
 */
class Processes extends Model
{
    use LogsActivity, HasFactory;

    protected static bool $logFillable = true;

    protected $fillable
        = [
            'name',
            'source_id',
            'destination_id',
            'values',
        ];

    public function streams()
    {
        return $this->hasMany(Streams::class, 'process_id', 'id');
    }

    public function source()
    {
        return $this->hasOne(Source::class, 'id', 'source_id');
    }

    public function destination()
    {
        return $this->hasOne(Destination::class, 'id', 'destination_id');
    }


    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }
}
