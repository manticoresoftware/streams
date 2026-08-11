<?php

namespace Tests\Feature\Routes;


use Tests\TestCase;
use Tests\Traits\AuthTrait;

/**
 * @group application
 */
class ApiTokenControllerTest extends TestCase
{
    use AuthTrait;

    public function testIndex()
    {
        $response = $this->actingAs($this->getAdmin())->get('/tokens');

        $response->assertStatus(200);
        $response->assertSee('API access tokens');
    }


    public function testTokenRemove()
    {

        $user     = $this->getManager();
        $response = $this->actingAs($user)->get('/tokens/remove');

        $response->assertStatus(302);

        $this->assertNull($user->api_token);
    }

    public function testTokenUpdate()
    {

        $user     = $this->getAdmin();
        $oldToken = $user->api_token;
        $response = $this->actingAs($user)->get('/tokens/update');

        $response->assertStatus(200);

        $this->assertTrue($oldToken != $user->api_token);
    }
}
