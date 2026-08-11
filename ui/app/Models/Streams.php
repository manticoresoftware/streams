<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Class Streams
 *
 * @package App
 * @mixin \Eloquent
 */
class Streams extends Model
{
    use LogsActivity, HasFactory;

    protected static bool $logFillable = true;

    protected $fillable
        = [
            'id',
            'user_id',
            'process_id',
            'stopped'
        ];

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function process()
    {
        return $this->hasOne(Processes::class, 'id', 'process_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }
}
