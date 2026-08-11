<?php

namespace Tests\Traits;

use App\Models\User;

/**
 * Trait for authentication helpers in tests
 */
trait AuthTrait
{
    /**
     * Get an admin user for testing
     *
     * @return User|null
     */
    protected function getAdmin()
    {
        return User::where(['role_id' => 1])->whereNotNull('api_token')->first();
    }

    /**
     * Get a manager user for testing
     *
     * @return User|null
     */
    protected function getManager()
    {
        return User::where(['role_id' => 2])->first();
    }
}