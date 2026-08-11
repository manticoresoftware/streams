<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Variable extends Model
{
    use LogsActivity, HasFactory;

    protected static bool $logFillable = true;
    protected $table = 'variables';

    protected $fillable
        = [
            'id',
            'name',
            'text',
            'stream_id',
        ];

    protected $casts
        = [
            'stream_id' => 'string',
        ];

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function getRouteKeyName()
    {
        return "name";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }
}
