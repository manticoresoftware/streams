<?php

namespace Tests\Feature\Routes;


use App\Models\Streams;
use App\Models\User;
use App\Services\Curl\CurlService;
use App\Services\ManticoreService;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Tests\Traits\AuthTrait;

/**
 * @group application
 */
class ManagerTest extends TestCase
{
    use AuthTrait;

    public function testManagerRedirect()
    {
        $user = $this->getManager();

        $response = $this->actingAs($user)->get("/");


        $response->assertStatus(302);
        $response->assertRedirect('manager/home');
    }

    /**
     * @test
     */
    public function denyManagerNoStreams()
    {
        $user = User::where(['role_id' => 2])->doesntHave('streams')->first();

        $response = $this->actingAs($user)->get("/manager/home");


        $response->assertStatus(302);
        $response->assertRedirect('manager/emptyAssigns');
    }


    /**
     * @test
     */
    public function getProcessListForOwner()
    {
        Streams::factory()->create();
        Streams::factory()->create();

        $user = User::where(['role_id' => 2])->has('streams')->first();

        if ($user->api_token === null) {
            $user->api_token = \Illuminate\Support\Str::random();
            $user->save();
        }

        $streamsCount = $user->streams()->count();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json'
        ])->get("/api/manager/process/get");

        $content = json_decode($response->content());

        self::assertSame(count($content->data), $streamsCount);

        $response->assertStatus(200);
    }


    /**
     * @test
     */
    public function getProcessListForNonOwner()
    {
        $user = User::where(['role_id' => 2])->whereNotNull('api_token')
            ->doesntHave('streams')->first();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json'
        ])->get("/api/manager/process/get");

        $response->assertExactJson(
            [
                'message' => 'Current user has no assigned streams',
                'status' => CurlService::STATUS_ERROR,
            ]
        );
        $response->assertStatus(404);
    }

    /**
     * @test
     */
    public function denyManagerForeign()
    {
        $user = User::where(['role_id' => 2])->whereNotNull('api_token')
            ->has('streams')->first();

        $streams = $user->streams()->latest()->get()->toArray();
        $url = '/api/manager/rules/searchExtended?streamId='
            . ($streams[0]['id'] + 1) . '&query=test';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json'
        ])->get($url);

        $response->assertExactJson(
            [
                'status' => CurlService::STATUS_ERROR,
                'message' => 'Current user can\'t get access to selected stream'
            ]
        );
        $response->assertStatus(403);
    }

    /**
     * @test
     */
    public function allowManagerOwn()
    {
        $user = User::where(['role_id' => 2])->whereNotNull('api_token')
            ->has('streams')->first();

        $streams = $user->streams()->latest()->get()->toArray();
        $url = '/api/manager/rules/searchExtended?streamId='
            . ($streams[0]['id']) . '&query=test';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json'
        ])->get($url);

        $response->assertStatus(200);
    }

    /**
     * @test
     */
    public function getRulesJsonOnInaccessibleManticore(): void
    {
        $error = 'Connect error';
        $this->instance(
            ManticoreService::class,
            Mockery::mock(ManticoreService::class,
                function ($mock) use ($error) {
                    $mock->shouldReceive('getError')->twice()
                        ->andReturn($error);
                    $mock->shouldReceive('setStream')->once();
                })
        );

        $user = User::where(['role_id' => 2])->whereNotNull('api_token')
            ->has('streams')->first();

        $streams = $user->streams()->latest()->get()->toArray();
        $url = '/api/manager/rules/get?streamId=' . ($streams[0]['id']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json'
        ])->get($url);

        $response->assertStatus(400);
        $response->assertJsonPath('message', $error);
    }


    /**
     * @test
     */
    public function homeOnInaccessibleManticore(): void
    {
        $this->instance(
            ManticoreService::class,
            Mockery::mock(ManticoreService::class, function ($mock) {
                $mock->shouldReceive('getFields')->once()->andReturn([]);
            })
        );

        $user = User::where(['role_id' => 2])->whereNotNull('api_token')
            ->has('streams')->first();

        $streams = $user->streams()->latest()->get()->toArray();
        $url = '/manager/home?streamId=' . ($streams[0]['id']);

        $response = $this->actingAs($user)->get($url);

        $response->assertStatus(200);
    }


    /**
     * @test
     *
     * @return void
     */

    public function managerGetErrorIfTriesToAddRuleWithManticoreUnavailable()
    {
        $user = User::where(['role_id' => 2])->whereNotNull('api_token')
            ->first();
        \Auth::login($user);

        $this->instance(
            ManticoreService::class,
            Mockery::mock(ManticoreService::class,
                function (MockInterface $mock) {
                    $mock->shouldReceive('connect')->andReturn(false);
                    $mock->shouldReceive('getError')->andReturn(['aaa']);
                })
        );

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json'
        ])
            ->post(
                "/api/manager/rules/add",
                [
                    'rule_text' => 'myQuery'
                ]
            );

        $response->assertStatus(400);
        $response->assertExactJson(['message' => ['aaa'], "status" => "error"]);
    }


    /**
     * @test
     *
     * @return void
     */

    public function managerGetErrorIfTriesToAddRuleWithManticoreUnavailableViaAPI(
    )
    {
        $user = User::where(['role_id' => 2])->whereNotNull('api_token')
            ->first();

        $this->instance(
            ManticoreService::class,
            Mockery::mock(ManticoreService::class,
                function (MockInterface $mock) {
                    $mock->shouldReceive('connect')->andReturn(false);
                    $mock->shouldReceive('getError')->andReturn(['aaa']);
                })
        );

        $response = $this->actingAs($user)
            ->post(
                "/manager/addRule",
                [
                    'rule_text' => 'myQuery'
                ]
            );

        $response->assertStatus(400);
        $response->assertExactJson(['message' => ['aaa'], "status" => "error"]);
    }

    /**
     * @test
     *
     * @return void
     */
    public function managerCanReplaceExistingRule()
    {
        $user = User::where(['role_id' => 2])
            ->whereNotNull('api_token')
            ->first();

        \Auth::login($user);

        $ms = app(ManticoreService::class);
        $ms->truncateRules();

        $searchRuleTag = 'my_custom_tag';
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json'
        ])
            ->post(
                "/api/manager/rules/add",
                [
                    'rule_text' => 'myQuery',
                    'rule_tags' => $searchRuleTag,
                    'rule_external' => 'whatever you want',
                    'rule_highlighting' => 'true'
                ]
            );

        $insertedRuleData = json_decode($response->content(), true);
        $insertedRuleData = $insertedRuleData['data'];

        $originalTags = json_decode($insertedRuleData['tags'], true);
        $modifiedTags = $originalTags;
        $modifiedTags['tag'] = 'new tag';
        $modifiedTags['externalQuery'] = 'new external query';
        $modifiedTags['highlighting'] = false;


        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer ' . $user->api_token,
                'Accept' => 'application/json'
            ])
            ->post(
                "/api/manager/rules/replace",
                [
                    'id' => $insertedRuleData['id'],
                    'query' => $insertedRuleData['query'],
                    'tags' => $searchRuleTag,
                    'weakTags' => false,
                    'weakQuery' => false,
                    'newData' => [
                        'id' => $insertedRuleData['id'],
                        'query' => $insertedRuleData['query'],
                        'tags' => json_encode($modifiedTags)
                    ]
                ]
            );

        $response->assertExactJson(['message' => 'Rules updated']);
        $response->assertStatus(200);


        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json'
        ])->post("/api/manager/rules/searchExtended", [
            'id' => $insertedRuleData['id']
        ]);

        $response = json_decode($response->content(), true);

        unset($response['data'][0]['statistic']);

        $this->assertSame([
            'data' => [
                [
                    'id' => $insertedRuleData['id'],
                    'query' => $insertedRuleData['query'],
                    'tags' => json_encode($modifiedTags),
                    'filters' => ""
                ]
            ],
            'recordsTotal' => "1",
            'recordsFiltered' => "1",
            'all_rules_count' => "1"

        ],
            $response
        );
    }

    /**
     * @test
     * @return void
     */
    public function managerCantReplaceNonExistingRule()
    {
        $user = User::where(['role_id' => 2])->whereNotNull('api_token')
            ->first();
        \Auth::login($user);

        $ms = app(ManticoreService::class);
        $ms->truncateRules();


        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer ' . $user->api_token,
                'Accept' => 'application/json'
            ])
            ->post(
                "/api/manager/rules/replace",
                [
                    'id' => -1,
                    'query' => '',
                    'tags' => '',
                    'weakTags' => false,
                    'weakQuery' => false,
                    'newData' => [
                        'id' => 999,
                        'query' => 'new query'
                    ]
                ]
            );

        $response->assertExactJson(['message' => 'Update list are empty']);
        $response->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * @test
     * @return void
     */
    public function managerGotErrorTryingToPassEmptyReplacementData()
    {
        $user = User::where(['role_id' => 2])->whereNotNull('api_token')
            ->first();
        \Auth::login($user);

        $ms = app(ManticoreService::class);
        $ms->truncateRules();


        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer ' . $user->api_token,
                'Accept' => 'application/json'
            ])
            ->post("/api/manager/rules/replace", ['id' => -1]);

        $response->assertExactJson(['message' => 'Request key newData must be array']);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
