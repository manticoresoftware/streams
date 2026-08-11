<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

use Tests\TestCase;

/**
 * Database setup tests - must run first
 *
 * @group setup
 */
class DatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        $this->seed('DatabaseSeeder');
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function testCheckUsersTableExist()
    {

        if ( ! empty(getenv('SUPERADMIN_EMAIL'))) {
            $email = getenv('SUPERADMIN_EMAIL');
        } else {
            $email = 'admin@example.com';
        }

        $this->assertDatabaseHas('users', [
            'email' => $email,
        ]);
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function testCheckRolesTableExist()
    {
        $this->assertDatabaseHas('roles', [
            'id' => 1,
            'name'=> 'admin'
        ]);

        $this->assertDatabaseHas('roles', [
            'id' => 2,
            'name'=> 'manager'
        ]);
    }
}
