<?php

namespace Tests\Feature\Routes;

use Tests\TestCase;
use Tests\Traits\AuthTrait;

/**
 * @group application
 */

class BannedMiddlewareTest extends TestCase
{
    use AuthTrait;

    public function testTrashedUser()
    {

        $admin = $this->getAdmin();
        $admin->delete();

        $response = $this->actingAs($admin)->get("/");


        $admin->restore();

        $response->assertStatus(302);
        $response->assertRedirect('login');
    }

    public function testNormalUser()
    {

        $admin = $this->getAdmin();
        $response = $this->actingAs($admin)->get("/");
        $response->assertStatus(302);
        $response->assertRedirect('admin/home');
    }

}
