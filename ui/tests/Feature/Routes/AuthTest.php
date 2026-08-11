<?php

namespace Tests\Feature\Routes;

use DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

/**
 * @group application
 */
class AuthTest extends TestCase
{

    public function testIndexRedirect()
    {
        $response = $this->get('/');
        $response->assertStatus(302);
        $response->assertRedirect('/login');
    }


    public function testLogin()
    {

        $response = $this->get('/admin/home');
        $response->assertStatus(302);
        $response->assertRedirect('/login');

    }


    public function testLogout()
    {

        $response = $this->get('/logout');
        $response->assertRedirect('/login');
        $response->assertStatus(302);
    }

    public function testForgotPassword()
    {
        $response = $this->get('password/reset');
        $response->assertSee('Reset Password');
    }
}
