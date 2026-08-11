<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

/**
 * Class User
 *
 * @package App
 * @mixin \Eloquent
 */
class User extends Authenticatable
{
    use Notifiable, SoftDeletes, LogsActivity, HasFactory;

    protected static bool $logFillable = true;

    protected static array $ignoreChangedAttributes = ['process', 'updated_at'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable
        = [
            'name',
            'email',
            'password',
            'role_id',
            'banned',
            'api_token',
        ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden
        = [
            'password',
            'remember_token',
        ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts
        = [
            'email_verified_at' => 'datetime',
        ];

    protected $errors;


    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }


    /**
     * relationship
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function streams()
    {
        return $this->hasMany(Streams::class, 'user_id', 'id');
    }


    /**
     * @param  string|array  $roles
     *
     * @return bool
     */
    public function authorizeRoles($roles)
    {
        if (is_array($roles)) {
            return $this->hasAnyRole($roles)
                || abort(401, 'This action is unauthorized.');
        }

        return $this->hasRole($roles)
            || abort(401, 'This action is unauthorized.');
    }

    /**
     * Check multiple roles
     *
     * @param  array  $roles
     *
     * @return bool
     */
    public function hasAnyRole($roles)
    {
        return null !== $this->role()->whereIn('name', $roles)->first();
    }

    /**
     * Check one role
     *
     * @param  string  $role
     *
     * @return bool
     */
    public function hasRole($role)
    {
        return null !== $this->role()->where('name', $role)->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }
}
