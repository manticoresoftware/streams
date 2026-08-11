<?php

namespace Tests\Feature\Routes;

use App\Models\User;
use App\Services\ColumnarService;
use App\Services\Curl\CurlService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Tests\Traits\AuthTrait;


/**
 * @group application
 */
class AdminControllerTest extends TestCase
{
    use AuthTrait;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory(10)->create();
    }

    public function testHomeRedirect()
    {
        $response = $this->actingAs($this->getAdmin())->get('/home');
        $response->assertStatus(302);
        $response->assertRedirect('/admin/home');
    }

    public function testRightUser()
    {
        $response = $this->actingAs($this->getAdmin())->get('/admin/home');
        $response->assertStatus(200);
        $response->assertSee('Add user');
    }


    public function testWrongUser()
    {
        $response = $this->actingAs($this->getManager())->get('/admin/home');
        $response->assertStatus(302);
    }

    public function testGetUsersList()
    {
        $usersCountInDatabase = User::count('id');
        if ($usersCountInDatabase > 50) {
            $usersCountInDatabase = 50;
        }
        $response = $this->actingAs($this->getAdmin())
            ->getJson('/admin/getUsersList');
        $response->assertStatus(200);

        $content = json_decode($response->content());
        $this->assertTrue(count($content->data) == $usersCountInDatabase);
    }

    public function testAddUser()
    {
        $data = User::factory()->make();

        $response = $this->actingAs($this->getAdmin())->post('/admin/addUser',
            [
                'email' => $data->email,
                'name' => Str::random(8),
                'password' => Str::random(10),
            ]);

        $user = User::where(['email' => $data->email])->first();

        $response->assertStatus(200);
        $response->assertExactJson([
            "success" => "Record is successfully added",
            "token" => $user->api_token,
            "id" => $user->id,
        ]);
    }


    public function testAddUserFail()
    {
        $response = $this->actingAs($this->getAdmin())->post('/admin/addUser',
            [
                'email' => 'wrong mail',
                'name' => 'dot.',
                'password' => '1',
                'token' => 123,
            ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJsonValidationErrors([
            'email', 'name', 'password', 'token'
        ]);
    }

    public function testEditUserSetAdmin()
    {
        $manager = $this->getManager();

        $response = $this->actingAs($this->getAdmin())->post('/admin/editUser',
            [
                'user_id' => $manager->id,
                'role_id' => 1,
            ]);


        $newAdmin = User::find($manager->id);

        $response->assertExactJson(["message" => "Account has been updated!"]);
        $this->assertTrue($newAdmin->role_id == 1);

        $newAdmin->role_id = 2;
        $newAdmin->save();
    }


    public function testEditUserSetManager()
    {
        $admin = $this->getAdmin();

        $response = $this->actingAs($admin)->post('/admin/editUser',
            [
                'role_id' => 2,
            ]);

        $response->assertExactJson(['message' => 'Pass the user ID']);


        $response = $this->actingAs($admin)->post('/admin/editUser',
            [
                'user_id' => $admin->id,
                'role_id' => 2,
            ]);

        $response->assertExactJson(['message' => 'You can\'t update yourself']);


        $user = User::where(['role_id' => 1])->where('id', '!=', $admin->id)
            ->first();


        $response = $this->actingAs($admin)->post('/admin/editUser',
            [
                'user_id' => $user->id,
                'role_id' => 99,
            ]);

        $response->assertExactJson(['message' => 'Wrong role']);


        $response = $this->actingAs($admin)->post('/admin/editUser',
            [
                'user_id' => $user->id,
                'role_id' => 2,
            ]);


        $newManager = User::find($user->id);

        $response->assertExactJson(["message" => "Account has been updated!"]);
        $this->assertTrue($newManager->role_id == 2);

        $newManager->role_id = 1;
        $newManager->save();
    }

    public function testReissueToken()
    {
        $user = $this->getAdmin();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json',
        ])->post('/api/admin/token/reissue',
            ['user_id' => $user->id, 'token' => Str::random(32)]);

        $response->assertExactJson([
            'message' => "User token successfully changed",
        ]);
    }


    public function testReissueTokenWrongToken()
    {
        $user = $this->getAdmin();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json',
        ])->post('/api/admin/token/reissue',
            ['user_id' => $user->id, 'token' => Str::random()]);

        $response->assertExactJson([
            'errors' => ['token' => 'The token must be at least 32 characters.'],
        ]);
    }

    public function testReissueTokenWrongUser()
    {
        $user = $this->getAdmin();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json',
        ])->post('/api/admin/token/reissue', ['token' => Str::random(32)]);

        $response->assertExactJson([
            'errors' => ['user_id' => 'The user id field is required.'],
        ]);
    }

    public function testReissueTokenWrongUserInTable()
    {
        $user = $this->getAdmin();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json',
        ])->post('/api/admin/token/reissue',
            ['user_id' => -1, 'token' => Str::random(32)]);

        $response->assertExactJson(
            ['message' => 'Can\'t find user']
        );
    }


    /**
     * @test
     */

    public function adminGetExceptionOnGraphWithoutActinAs()
    {
        $user = $this->getAdmin();
        $response = $this->json('get', '/api/admin/getGraph/', [
            'dateFrom' => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
            'dateTo' => Carbon::now()->format('Y-m-d H:i:s'),
        ], [
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(401);
    }

    /**
     * @test
     */

    public function adminGetEmptyNonExistingStreamGraph()
    {
        $user = $this->getAdmin();

        $response = $this->json('get', '/api/admin/getRuleStatData/-1',
            ['actingAs' => -1], [
                'Authorization' => 'Bearer ' . $user->api_token,
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJson(['message' => 'dateFrom field is mandatory']);
    }

    /**
     * @test
     */
    public function adminGetEmptyNonExistingRuleGraph()
    {
        $user = $this->getAdmin();

        $response = $this->json('get', '/api/admin/getGraph/',
            ['actingAs' => -1, 'section' => 'processing-lag'], [
                'Authorization' => 'Bearer ' . $user->api_token,
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJson(['message' => 'dateFrom field is mandatory']);
    }

    /**
     * @test
     */
    public function adminGetGraph()
    {
        $this->instance(ColumnarService::class,
            Mockery::mock(ColumnarService::class, function ($mock) {
                $mock->shouldReceive('getError')->shouldReceive('setStream')
                    ->once();
                $mock->shouldReceive('getGraph')
                    ->once()
                    ->andReturn([
                        'categories' => [
                            Carbon::now()->format('Y-m-d H:i:s')
                        ], 'values' => [22]
                    ]);
            }));


        $user = $this->getAdmin();
        $response = $this->json('get', '/api/admin/getGraph/', [
            'actingAs' => 1,
            'section' => 'processing-lag',
            'dateFrom' => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
            'dateTo' => Carbon::now()->format('Y-m-d H:i:s'),
        ], [
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['type', 'data', 'options', 'append']);
    }
}
