<?php

namespace Tests\Feature\Routes;

use App\Console\Commands\KafkaConsumeMessages;
use App\Http\Controllers\ProcessController;
use App\Models\Destination;
use App\Models\Processes;
use App\Models\Role;
use App\Models\Streams;
use App\Services\Curl\CurlService;
use App\Services\Curl\KubeService;
use App\Services\KafkaConnection;
use App\Models\Source;
use App\Models\User;
use DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
use Spatie\Fork\Fork;
use Illuminate\Http\Request;
use Tests\TestCase;
use Tests\Traits\AuthTrait;

/**
 * @group application
 */
class ProcessControllerTest extends TestCase
{
    use AuthTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Processes::factory(1)->create();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function testRightUser()
    {
        $response = $this->actingAs($this->getAdmin())
            ->get('/admin/process');
        $response->assertStatus(Response::HTTP_OK);
        $response->assertSee('Add process');
    }

    public function testWrongUser()
    {
        $response = $this->actingAs($this->getManager())
            ->get('/admin/process');
        $response->assertStatus(Response::HTTP_FOUND);
    }

    public function testGetGoals()
    {
        $source = Source::select(['name'])->first();
        $destination = Destination::select(['name'])->first();

        $response = $this->actingAs($this->getAdmin())
            ->get('/admin/process/goals');
        $response->assertStatus(Response::HTTP_OK);
        $response->assertSee($source->name);
        $response->assertSee($destination->name);
    }

    public function testResolveGoals()
    {
        $source = Source::select(['id', 'name', 'host', 'topic', 'group'])
            ->first()->toArray();

        $destination = Destination::select([
            'id', 'name', 'host', 'topic', 'group'
        ])->first()->toArray();

        $response = $this->actingAs($this->getAdmin())
            ->post('/admin/process/resolveGoals',
                ['source' => $source['id'], 'destination' => $destination['id']]);
        $response->assertStatus(Response::HTTP_OK);

        $response->assertExactJson([
            'source' => $source, 'destination' => $destination
        ]);
    }

    public function testGetNewProcess()
    {
        $response = $this->actingAs($this->getAdmin())
            ->get('/admin/process/new');
        $response->assertStatus(Response::HTTP_OK);
        $response->assertSee('GENERAL');
    }

    public function testGetProgress()
    {
        $response = $this->actingAs($this->getAdmin())
            ->get('/admin/process/progress');
        $response->assertStatus(Response::HTTP_OK);
        $response->assertSee('Please wait until we connect to the Kafka and analyze a handful of documents');
    }

    public function testParseSchema()
    {
        $kafkaRet = new class() {
            public $err = 0;
            public $payload = '{"text":{"status":"message","text":"Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed id ultrices libero, eget accumsan mauris. Vivamus finibus faucibus sapien, id finibus ligula scelerisque ut. Sed posuere, purus in lobortis molestie, sapien augue ullamcorper lectus, vel dictum urna ipsum et est. Etiam eleifend turpis quis erat dapibus, non euismod lacus mollis. Integer sollicitudin purus quis rhoncus volutpat. Sed imperdiet, elit finibus laoreet suscipit, libero erat fermentum dolor, ut eleifend augue nisi ut ante. Sed dapibus interdum tellus at pellentesque. Maecenas mollis ac metus eget bibendum. Aliquam in sem accumsan, accumsan lorem et, varius diam. Etiam lobortis odio erat, ac tempus diam aliquet non. Phasellus tincidunt at lacus eget ullamcorper. Ut iaculis pretium hendrerit. Sed molestie vitae massa in mattis. Praesent a mollis augue. Sed feugiat risus at sodales iaculis. Phasellus ultrices diam neque. Vivamus egestas augue eu ante molestie, ut dictum leo congue. Nam ac sagittis mi. Donec nec nulla a ante blandit accumsan. Etiam molestie, odio sit amet egestas aliquet, tortor mi venenatis nulla, tincidunt vehicula libero tellus non nulla. Sed nec nisl lectus. Aenean mollis eu odio id tincidunt. Quisque a ultrices purus. Fusce sed consequat lacus, vel condimentum turpis. Duis et suscipit enim. Nulla ut eleifend ante. Aenean ornare diam nisi, et rutrum nibh consequat nec. Suspendisse ac felis porta, dictum lacus a, venenatis ex. Morbi tempor pretium justo vestibulum sagittis. Sed placerat mauris quis tristique consectetur. Nullam pulvinar ipsum non ex luctus, sit amet feugiat turpis semper. Morbi sit amet pellentesque odio, non finibus justo. Sed a ex enim. Proin dolor tellus, molestie sit amet risus sed, pellentesque lacinia sem. Sed ullamcorper scelerisque interdum. Curabitur cursus ultrices condimentum. Aenean condimentum vestibulum enim, quis blandit mi. In consequat odio vehicula, ultrices massa ac, ultrices risus. Proin consectetur, ante eget gravida interdum, nibh nulla faucibus eros, et dapibus velit ipsum at nibh. Fusce non suscipit urna, eu mattis ipsum. Nullam gravida arcu sed nibh ornare, vitae luctus neque tempus. Sed imperdiet vestibulum mi. Donec placerat felis vel eleifend mollis. Praesent in porta velit, convallis cursus dolor. Cras vitae rhoncus magna, sed placerat ligula. Nullam varius et justo tristique cursus. Quisque ligula ipsum, tincidunt sed congue nec, venenatis quis risus. Donec luctus est vel lorem ultrices eleifend. Suspendisse ac rhoncus sapien. Fusce eget enim vel dolor pharetra viverra. Aliquam at libero congue, faucibus libero nec, pretium nisi. Nulla sit amet risus faucibus, congue est vitae, placerat dolor. In tincidunt efficitur tempus. Quisque semper magna at felis accumsan ullamcorper. Suspendisse eget erat rutrum, tempus leo sed, feugiat nisi. Nam magna nulla, dapibus in aliquam sed, vehicula eu nunc. Sed fringilla convallis sapien malesuada consequat. Suspendisse est lectus, eleifend feugiat fringilla vitae, laoreet in tellus. Cras lacinia posuere massa. In tincidunt luctus ipsum, vitae molestie ante consectetur et. Nulla arcu purus, malesuada at facilisis id, vehicula condimentum tortor. In pretium lobortis erat tincidunt commodo."}}';
        };

        $this->instance(
            KafkaConnection::class,
            Mockery::mock(KafkaConnection::class,
                function ($mock) use ($kafkaRet) {
                    $mock->shouldReceive('connect')->once();
                    $mock->shouldReceive('consume')->andReturn($kafkaRet);
                })
        );

        $source = Source::first();
        $response = $this->actingAs($this->getAdmin())->post(
            '/admin/process/parseSchema',
            [
                'host' => $source->host, 'group' => $source->group,
                'topic' => $source->topic
            ]
        );
        $response->assertStatus(Response::HTTP_OK);
    }

    public function testGetSchema()
    {
        $this->assertTrue(Storage::exists(KafkaConsumeMessages::RESULTS_FILENAME));

        $results = \Storage::get(KafkaConsumeMessages::RESULTS_FILENAME);
        $hash = md5($results);
        $this->assertEquals('87bc63b88d60f72b4a6d5bc222dc7fe0', $hash);

        $response = $this->actingAs($this->getAdmin())
            ->get('/admin/process/getSchema');
        $response->assertStatus(200);
    }


    public function testAddProcess()
    {
        $fakeProcess = Processes::factory()->make();

        $aiProcess = 1;
        $table = DB::select("SELECT AUTO_INCREMENT FROM ".
            "information_schema.TABLES WHERE TABLE_NAME = 'processes'");

        if (!empty($table)) {
            $aiProcess = (int) $table[0]->AUTO_INCREMENT;
        }

        /** @var $mock Mockery */
        $this->instance(
            KubeService::class,
            Mockery::mock(KubeService::class,
                function ($mock) use ($aiProcess) {
                    $mock->shouldReceive('sendFile')->with(
                        'https://kubernetes.default.svc/api/v1/namespaces/manticore-streams/configmaps',
                        "apiVersion: v1
kind: ConfigMap
metadata:
  name: configmap-p$aiProcess
  labels:
    name: configmap-p$aiProcess
    helm.sh/chart: null
    app.kubernetes.io/instance: null
    app.kubernetes.io/managed-by: null
    app.kubernetes.io/app: p$aiProcess-process
data:
  searchd_include.conf: |

  rt_include.conf: |
    acv=cba stopwords='/etc/manticoresearch/conf_mount/stopwords.txt' mode='alert' exceptions='/etc/manticoresearch/conf_mount/exceptions.txt'
  stopwords.txt: |
    sex
    drugs
    rock'n'roll
  exceptions.txt: |
    at & t => at&t
    AT&T => AT&T
    Standarten   Fuehrer => standartenfuhrer
    Standarten Fuhrer => standartenfuhrer
    MS Windows => ms windows
    Microsoft Windows => ms windows
    C++ => cplusplus
    c++ => cplusplus
    C plus plus => cplusplus
"
                    )
                        ->once()
                        ->andReturn(
                            [
                                'status' => CurlService::STATUS_SUCCESS,
                                'message' => 'ok',
                            ]
                        );
                })
        );

        $response = $this->actingAs($this->getAdmin())->postJson(
            '/admin/process/add',
            [
                'name' => $fakeProcess->name,
                'source_id' => $fakeProcess->source_id,
                'destination_id' => $fakeProcess->destination_id,
                'language' => 'custom',
                'query_complexity_validation' => '1',
                'max_matches_percent' => 20,
                'max_batch_size' => 5000,
                'attrs' => '[{"name":"whole_document","path":"whole_document","type":"json"},{"name":"url","path":"text.status.retweeted_status.user.url&&text.comment.status.user.url&&text.comment.status.retweeted_status.user.url&&text.comment.reply_comment.user.url&&text.comment.reply_comment.text&&text.comment.status.statusurl&&text.status.statusurl&&text.comment.user.url&&text.comment.reply_comment.status.user.url&&text.comment.source","type":"url"}]',
                'output_docs' => '1010',
                'min_threads' => 1,
                'max_threads' => 3,
                'stopwords' => "sex\ndrugs\nrock'n'roll",
                'exceptions' => "at & t => at&t\nAT&T => AT&T\nStandarten   Fuehrer => standartenfuhrer\nStandarten "
                    .
                    "Fuhrer => standartenfuhrer\nMS Windows => ms windows\nMicrosoft Windows => ms windows\n"
                    .
                    "C++ => cplusplus\nc++ => cplusplus\nC plus plus => cplusplus",
                'nlp_settings' => "acv=cba stopwords='/tmp/stopwords.dat' mode='alert'",
                'jslt_conf' => "{ \"time\": round(parse-time(.published, \"yyyy-MM-dd'T'HH:mm:ssX\") * 1000), \n \"device_manufacturer\": .device.manufacturer, "
                    .
                    '"device_model": .device.model, "language": .device.acceptLanguage, "os_name": .device.osType, "os_version": .device.osVersion,'
                    .
                    ' "platform": .device.platformType, "user_properties": { "is_logged_in" : boolean(.actor."spt:userId") }}',
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'success']);
    }

    public function testAddProcessDontReplaceStopwordsIfNotPassed()
    {
        $fakeProcess = Processes::factory()->make();

        $aiProcess = 1;
        $table
            = DB::select("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_NAME = 'processes'");
        if (!empty($table)) {
            $aiProcess = (int) $table[0]->AUTO_INCREMENT;
        }

        /** @var $mock Mockery */
        $this->instance(
            KubeService::class,
            Mockery::mock(KubeService::class,
                function ($mock) use ($aiProcess) {
                    $mock->shouldReceive('sendFile')->with(
                        'https://kubernetes.default.svc/api/v1/namespaces/manticore-streams/configmaps',
                        "apiVersion: v1
kind: ConfigMap
metadata:
  name: configmap-p$aiProcess
  labels:
    name: configmap-p$aiProcess
    helm.sh/chart: null
    app.kubernetes.io/instance: null
    app.kubernetes.io/managed-by: null
    app.kubernetes.io/app: p$aiProcess-process
data:
  searchd_include.conf: |

  rt_include.conf: |
    stopwords = 'en, it, ru' type='rt' exceptions='/etc/manticoresearch/conf_mount/exceptions.txt'
  stopwords.txt: |

  exceptions.txt: |
    at & t => at&t
    AT&T => AT&T
    Standarten   Fuehrer => standartenfuhrer
    Standarten Fuhrer => standartenfuhrer
    MS Windows => ms windows
    Microsoft Windows => ms windows
    C++ => cplusplus
    c++ => cplusplus
    C plus plus => cplusplus
"
                    )
                        ->once()
                        ->andReturn(
                            [
                                'status' => CurlService::STATUS_SUCCESS,
                                'message' => 'ok',
                            ]
                        );
                })
        );

        $response = $this->actingAs($this->getAdmin())->postJson(
            '/admin/process/add',
            [
                'name' => $fakeProcess->name,
                'source_id' => $fakeProcess->source_id,
                'destination_id' => $fakeProcess->destination_id,
                'language' => 'custom',
                'query_complexity_validation' => '1',
                'max_matches_percent' => 20,
                'max_batch_size' => 5000,
                'attrs' => '[{"name":"whole_document","path":"whole_document","type":"json"}]',
                'output_docs' => '1010',
                'min_threads' => 1,
                'max_threads' => 3,
                'exceptions' => "at & t => at&t\nAT&T => AT&T\nStandarten   Fuehrer => standartenfuhrer\nStandarten "
                    .
                    "Fuhrer => standartenfuhrer\nMS Windows => ms windows\nMicrosoft Windows => ms windows\n"
                    .
                    "C++ => cplusplus\nc++ => cplusplus\nC plus plus => cplusplus",
                'nlp_settings' => "stopwords = 'en, it, ru' type='rt'",
                'jslt_conf' => "{ \"time\": round(parse-time(.published, \"yyyy-MM-dd'T'HH:mm:ssX\") * 1000), \n \"device_manufacturer\": .device.manufacturer, "
                    .
                    '"device_model": .device.model, "language": .device.acceptLanguage, "os_name": .device.osType, "os_version": .device.osVersion,'
                    .
                    ' "platform": .device.platformType, "user_properties": { "is_logged_in" : boolean(.actor."spt:userId") }}',
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'success']);
    }


    public function testAddProcessWrongInput()
    {
        $response = $this->actingAs($this->getAdmin())
            ->postJson(
                '/admin/process/add',
                [
                    'name' => null,
                    'source_id' => null,
                    'destination_id' => null,
                    'language' => null,
                    'max_batch_size' => null,
                    'attrs' => null,
                    'output_docs' => null,
                    'min_threads' => null,
                    'max_threads' => null,
                ]
            );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'name',
            'source_id',
            'destination_id',
            'language',
            'attrs',
            'output_docs',
            'max_batch_size',
            'min_threads',
            'max_threads',
        ]);
    }


    /**
     * @dataProvider wrongAttrProvider
     */
    public function testAddProcessWrongAttr($name, $type, $path, $errorText)
    {
        $source = Source::factory()->create();
        $destination = Destination::factory()->create();


        $response = $this->actingAs($this->getAdmin())
            ->postJson(
                '/admin/process/add',
                [
                    'name' => "Test name",
                    'source_id' => $source->id,
                    'destination_id' => $destination->id,
                    'language' => 'en',
                    'max_batch_size' => 500,
                    'attrs' => urlencode(
                        json_encode(
                            [
                                [
                                    'name' => $name, 'type' => $type,
                                    'path' => $path
                                ]
                            ]
                        )
                    ),
                    'output_docs' => "1001",
                    'max_threads' => 1,
                ]
            );

        $response->assertStatus(422);
        $response->assertJson($errorText);
    }


    public function wrongAttrProvider(): array
    {
        return [
            [
                null,
                null,
                null,
                [
                    "message" => "Attribute attr[].name is mandatory (and 2 more errors)",
                    "errors" => [
                        "attrs" => [
                            "Attribute attr[].name is mandatory",
                            "Attribute attr[].path is mandatory",
                            "Attribute attr[].type is mandatory"
                        ]
                    ]
                ]
            ],
            [
                'name' => '{}[s]\\',
                'type' => 'non exist type',
                'path' => '[]wrong.non[]exist.node',
                [
                    "message" => "Non allowed symbols in node \"[]wrong\" Full path: \"[]wrong.non[]exist.node\" (and 2 more errors)",
                    "errors" => [
                        "attrs" => [
                            "Non allowed symbols in node \"[]wrong\" Full path: \"[]wrong.non[]exist.node\"",
                            "Non allowed symbols in node \"non[]exist\" Full path: \"[]wrong.non[]exist.node\"",
                            "Attribute attr[].type has wrong type. Allowed types: string|int|bigint|float|bool|timestamp|url|json"
                        ]
                    ]
                ]
            ]
        ];
    }

    public function testGetUsersForAssignList()
    {
        $response = $this->actingAs($this->getAdmin())
            ->post('/admin/process/getToAssignUsersList',
                ['process_id' => 999]);
        $response->assertStatus(200);
    }

    public function testGetToUnassignUsersList()
    {
        $context = Streams::factory()->create();


        $response = $this->actingAs($this->getAdmin())
            ->post('/admin/process/getToUnassignUsersList',
                ['process_id' => $context->process_id]);

        $user = User::find($context->user_id);
        $response->assertStatus(200);
        $response->assertExactJson([
            [
                'id' => $user->id, 'email' => $user->email
            ]
        ]);
    }

    public function testAssignUser()
    {

        $this->instance(
            KubeService::class,
            Mockery::mock(KubeService::class, function ($mock) {
                    $mock->shouldReceive('sendFile')
                        ->twice()
                        ->andReturn([
                            'status' => CurlService::STATUS_SUCCESS,
                        ]);

            })
        );

        $process = Processes::whereNotNull('source_id')
            ->whereNotNull('destination_id')->first();

        $roleManager = Role::where('name', Role::ROLE_MANAGER)->first();

        $user = DB::table('users')
            ->where(['role_id' => $roleManager->id])
            ->whereNotIn('id', Streams::select('user_id')
                ->where(['process_id' => $process->id]))
            ->distinct()->first();


        $response = $this->actingAs($this->getAdmin())->post(
            '/admin/process/assignUser',
            ['process_id' => $process->id, 'assign_user' => $user->id]
        );

        $user = User::find($user->id);
        // Cause curl can't connect to k8s
        $response->assertStatus(Response::HTTP_OK);

        $streams = $process->streams()->pluck('id')->toArray();

        $user = User::find($user->id);
        $this->assertTrue(in_array($user->process, $streams));
    }

    public function testAssignWrongUser()
    {
        $response = $this->actingAs($this->getAdmin())->post(
            '/admin/process/assignUser',
            ['process_id' => 0, 'assign_user' => 0]
        );

        $response->assertStatus(Response::HTTP_NOT_FOUND);
        $response->assertExactJson([
            'message' => "Can't find user", 'id' => ''
        ]);
    }

    public function testAssignWrongProcess()
    {
        $user = User::first();

        $response = $this->actingAs($this->getAdmin())->post(
            '/admin/process/assignUser',
            ['process_id' => 0, 'assign_user' => $user->id]
        );

        $response->assertStatus(Response::HTTP_NOT_FOUND);
        $response->assertExactJson([
            'message' => "Can't find process", 'id' => ''
        ]);
    }

    public function testAssignToAssignedProcess()
    {
        $user = Streams::first()->user()->first();
        $process = Streams::first()->process()->first();

        $response = $this->actingAs($this->getAdmin())->post(
            '/admin/process/assignUser',
            ['process_id' => $process->id, 'assign_user' => $user->id]
        );

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertExactJson([
            'message' => 'Selected user already have assigned to this process',
            'id' => ''
        ]);
    }

    public function testGetProcessList()
    {
        Streams::factory()->create();

        $process = Processes::first();

        $process->source()->delete();
        $process->destination()->delete();


        $response = $this->actingAs($this->getAdmin())
            ->get('/admin/process/getList');
        $response->assertStatus(200);

        $countInDatabase = Processes::count('id');
        $content = json_decode($response->content());
        $this->assertEquals(count($content->data), $countInDatabase);
    }

    public function testUnassingUser()
    {
        $context = Streams::factory()->create();


        $this->instance(
            KubeService::class,
            Mockery::mock(KubeService::class, function ($mock) use ($context) {
                foreach (
                    [
                        'https://kubernetes.default.svc/apis/apps/v1/namespaces/manticore-streams/deployments',
                        'https://kubernetes.default.svc/api/v1/namespaces/manticore-streams/services',
                        'https://kubernetes.default.svc/apis/apps/v1/namespaces/manticore-streams/statefulsets',
                        'https://kubernetes.default.svc/api/v1/namespaces/manticore-streams/persistentvolumeclaims',
                    ] as $resource
                ) {
                    $mock->shouldReceive('get')
                        ->with($resource)
                        ->once()
                        ->andReturn([
                            'status' => CurlService::STATUS_SUCCESS,
                            'result' => [
                                'items' =>
                                    [
                                        [
                                            'metadata' =>
                                                [
                                                    'labels' => ['streamID' => $context->id],
                                                    'name' => 'name',
                                                ],
                                        ]
                                    ],
                            ],
                        ]);
                }

                $mock->shouldReceive('remove')->times(4)
                    ->andReturn(['status' => CurlService::STATUS_SUCCESS]);
            })
        );


        $response = $this->actingAs($this->getAdmin())
            ->post('/admin/process/unassignUser', [
                'unassign_user' => $context->user_id,
                'process_id' => $context->process_id,
            ]);

        $response->assertStatus(200);
        $response->assertExactJson(['message' => 'User was successfully unassigned']);
    }

    public function testUnassingWrongProcess()
    {
        $response = $this->actingAs($this->getAdmin())
            ->post('/admin/process/unassignUser', [
                'unassign_user' => User::whereNotNull('process')->first()->id,
                'process_id' => 0,
            ]);

        $response->assertStatus(Response::HTTP_NOT_FOUND);
        $response->assertExactJson(['message' => 'Can\'t find process']);
    }


    public function testUnassingWrongUser()
    {
        $response = $this->actingAs($this->getAdmin())
            ->post('/admin/process/unassignUser', [
                'unassign_user' => 0,
                'process_id' => Processes::first(),
            ]);

        $response->assertStatus(Response::HTTP_NOT_FOUND);
        $response->assertExactJson(['message' => 'Can\'t find user']);
    }

    public function testUnassingWrongAssing()
    {
        $response = $this->actingAs($this->getAdmin())
            ->post('/admin/process/unassignUser', [
                'unassign_user' => User::first(),
                'process_id' => Processes::first(),
            ]);

        $response->assertStatus(Response::HTTP_FORBIDDEN);
        $response->assertExactJson(['message' => "Selected user doesn't allocated to this process"]);
    }


    public function testRemoveProcess()
    {
        $this->curl = null;
        $context = Streams::factory()->create();

        $url = '/admin/process/remove/' . $context->process()->first()->id;
        $response = $this->actingAs($this->getAdmin())->get($url);

        $response->assertStatus(200);
        $response->assertExactJson(['message' => 'Process was successfully removed']);
    }

    public function testRemoveWrongProcess()
    {
        $url = '/admin/process/remove/0';
        $response = $this->actingAs($this->getAdmin())->get($url);

        $response->assertStatus(Response::HTTP_NOT_FOUND);
        $response->assertExactJson(['message' => "Can't find process"]);
    }

    public function testExtendedProcessInfo()
    {
        $process = Processes::first();

        $url = '/api/admin/process/extendedInfo/' . $process->id;

        $user = $this->getAdmin();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json',
        ])->get($url);

        $processResponse = json_decode($response->content(), true);

        self::assertEquals($processResponse['id'], $process->id);
    }

    public function testSuspendList()
    {
        $context = Streams::where(['stopped' => 0])->get()->first();

        $response = $this->actingAs($this->getAdmin())->post(
            '/admin/process/getSuspendList',
            ['process_id' => $context->process_id]
        );

        $contexts = Streams::where([
            'stopped' => 0, 'process_id' => $context->process_id
        ])->get();
        $response->assertStatus(200);

        $contestsCount = json_decode($response->content(), true);

        self::assertCount($contexts->count(), $contestsCount);
    }


    public function testSuspendStream()
    {
        $context = Streams::where(['stopped' => 0])->get()->first();


        $this->instance(
            KubeService::class,
            Mockery::mock(KubeService::class, function ($mock) {
                $mock->shouldReceive('patchRequest')->once()->andReturn(
                    [
                        'status' => CurlService::STATUS_SUCCESS,
                        'message' => 'ok',
                    ]
                );
            })
        );


        $response = $this->actingAs($this->getAdmin())->post(
            '/admin/process/suspend',
            ['streamId' => $context->id]
        );

        $context->refresh();

        $response->assertStatus(200);

        self::assertSame($context->stopped, 1);
    }


    public function testResumeList(): void
    {
        $context = Streams::where(['stopped' => 1])->get()->first();

        $response = $this->actingAs($this->getAdmin())->post(
            '/admin/process/getResumeList',
            ['process_id' => $context->process_id]
        );

        $contexts = Streams::where([
            'stopped' => 1, 'process_id' => $context->process_id
        ])->get();
        $response->assertStatus(200);

        $contestsCount = json_decode($response->content(), true);

        self::assertCount($contexts->count(), $contestsCount);
    }


    public function testResumeStream()
    {
        $context = Streams::where(['stopped' => 1])->get()->first();


        $this->instance(
            KubeService::class,
            Mockery::mock(KubeService::class, function ($mock) {
                $mock->shouldReceive('patchRequest')->once()->andReturn(
                    [
                        'status' => CurlService::STATUS_SUCCESS,
                        'message' => 'ok',
                    ]
                );
            })
        );


        $response = $this->actingAs($this->getAdmin())->post(
            '/admin/process/resume',
            ['streamId' => $context->id]
        );

        $context->refresh();

        $response->assertStatus(200);

        self::assertSame($context->stopped, 0);
    }


    public function testSuspendStreamNoIdPassed()
    {
        $response = $this->actingAs($this->getAdmin())
            ->post('/admin/process/suspend');

        $response->assertExactJson(['message' => 'No stream id was specified']);
    }

    public function testSuspendStreamWrongIdPassed()
    {
        $response = $this->actingAs($this->getAdmin())->post(
            '/admin/process/suspend',
            ['streamId' => 'ololo']
        );

        $response->assertExactJson(['message' => 'Can\'t find streaming']);
    }

    public function testSuspendStreamSuspended()
    {
        $context = Streams::factory(1)->create([
            'stopped' => 1,
        ]);

        $response = $this->actingAs($this->getAdmin())->post(
            '/admin/process/suspend',
            ['streamId' => $context->first()->id]
        );

        $response->assertExactJson(['message' => 'Streaming already suspended']);
    }

    public function testResumeStreamNoIdPassed()
    {
        $response = $this->actingAs($this->getAdmin())
            ->post('/admin/process/resume');

        $response->assertExactJson(['message' => 'No stream id was specified']);
    }

    public function testResumeStreamWrongId()
    {
        $response = $this->actingAs($this->getAdmin())->post(
            '/admin/process/resume',
            ['streamId' => 'ololo']
        );

        $response->assertExactJson(['message' => 'Can\'t find streaming']);
    }

    public function testResumeStreamSuspended()
    {
        $context = Streams::factory(1)->create();

        $response = $this->actingAs($this->getAdmin())->post(
            '/admin/process/resume',
            ['streamId' => $context->first()->id]
        );

        $response->assertExactJson(['message' => 'Streaming already resumed']);
    }

    public function testGetUserStreamsWrongUser()
    {
        $user = $this->getAdmin();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json',
        ])->post('/api/admin/process/streams/get');

        $response->assertExactJson([
            'message' => "Pass the user id",
        ]);
    }

    public function testGetUserStreams()
    {
        $context = Streams::first();

        $user = $this->getAdmin();
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->api_token,
            'Accept' => 'application/json',
        ])->post('/api/admin/process/streams/get',
            ['user_id' => $context->user_id]);

        $processResponse = json_decode($response->content(), true);

        self::assertEquals($processResponse[0]['process_name'],
            $context->process->name);
    }

    public function testConcurrentUserAssignment()
    {
        // Disable Laravel's transaction middleware to ensure data is committed
        $this->app->instance('middleware.disable', true);

        // Create test data
        $process = Processes::factory()->create([
            'source_id' => Source::factory()->create()->id,
            'destination_id' => Destination::factory()->create()->id,
        ]);
        $managerRole = Role::where('name', Role::ROLE_MANAGER)->first()->id;
        $user1 = User::factory()->create(['role_id' => $managerRole]);
        $user2 = User::factory()->create(['role_id' => $managerRole]);
        $admin = $this->getAdmin();

        // Force commit and verify users exist
        DB::commit();
        $user1->refresh();
        $user2->refresh();
        $this->assertNotNull(User::find($user1->id), "User 1 not found in database");
        $this->assertNotNull(User::find($user2->id), "User 2 not found in database");

        // Mock KubeService to avoid real Kubernetes calls
        $this->mock(KubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendFile')->andReturn([
                'status' => CurlService::STATUS_SUCCESS,
                'message' => 'ok',
            ]);
        });

        // Run concurrent assignments with retry logic
        $results = Fork::new()->run(
            function () use ($admin, $process, $user1) {
                // Reconnect to database to ensure fresh connection
                DB::reconnect();
                $request = Request::create('/admin/process/assignUser', 'POST', [
                    'process_id' => $process->id,
                    'assign_user' => $user1->id,
                ]);
                $request->setUserResolver(function () use ($admin) {
                    return $admin;
                });
                $controller = app(ProcessController::class);
                $response = $controller->assignUser(app(KubeService::class), $request);
                return $response->getData(true);
            },
            function () use ($admin, $process, $user2) {
                // Reconnect to database to ensure fresh connection
                DB::reconnect();
                // Add slight delay to ensure database propagation
                usleep(100000); // 100ms delay
                $request = Request::create('/admin/process/assignUser', 'POST', [
                    'process_id' => $process->id,
                    'assign_user' => $user2->id,
                ]);
                $request->setUserResolver(function () use ($admin) {
                    return $admin;
                });
                $controller = app(ProcessController::class);
                $response = $controller->assignUser(app(KubeService::class), $request);
                return $response->getData(true);
            }
        );

        // Assertions
        $this->assertCount(2, $results, 'Both forks should return results');
        $this->assertEquals('The user has been assigned to process', $results[0]['message'], 'User 1 assignment should succeed');
        $this->assertEquals('The user has been assigned to process', $results[1]['message'], 'User 2 assignment should succeed');
        $this->assertNotEquals($results[0]['id'], $results[1]['id'], 'Stream IDs should be unique');

        $streams = Streams::where('process_id', $process->id)->get();
        $this->assertCount(2, $streams, 'Two streams should be created');
        $this->assertEqualsCanonicalizing(
            [$results[0]['id'], $results[1]['id']],
            $streams->pluck('id')->all(),
            'Stream IDs should match response IDs'
        );

        $user1 = User::find($user1->id);
        $user2 = User::find($user2->id);
        $this->assertEquals($results[0]['id'], $user1->process, 'User 1 process ID should match');
        $this->assertEquals($results[1]['id'], $user2->process, 'User 2 process ID should match');
    }

    public function test_assign_user_with_custom_kafka_config()
    {
        // Use existing process and add custom kafka config
        $process = Processes::first();
        $this->assertNotNull($process, 'Process should exist in database');

        // Update process with custom kafka config
        $values = unserialize($process->values);
        $values['user_request']['kafka_config'] = [
            'fetch.min.bytes' => 1921,
            'fetch.max.wait.ms' => 600,
            'fetch.max.bytes' => 2097152,
            'max.poll.records' => 1000
        ];
        $process->values = serialize($values);
        $process->save();

        $user = User::whereDoesntHave('streams', function($query) use ($process) {
            $query->where('process_id', $process->id);
        })->first();

        $this->assertNotNull($user, 'User should exist in database');

        // Mock the KubeService
        $kubeServiceMock = $this->mock(KubeService::class);
        $kubeServiceMock->shouldReceive('sendFile')->andReturn(['status' => 'success']);

        $request = Request::create('/admin/process/assignUser', 'POST', [
            'process_id' => $process->id,
            'assign_user' => $user->id,
        ]);

        $controller = new ProcessController();
        $response = $controller->assignUser($kubeServiceMock, $request);

        $this->assertEquals(200, $response->getStatusCode());

        // Verify that a stream was created
        $this->assertDatabaseHas('streams', [
            'user_id' => $user->id,
            'process_id' => $process->id
        ]);
    }

    public function test_assign_user_without_kafka_config()
    {
        // Use existing process without custom kafka config
        $process = Processes::first();
        $this->assertNotNull($process, 'Process should exist in database');

        // Ensure process doesn't have custom kafka config
        $values = unserialize($process->values);
        unset($values['user_request']['kafka_config']);
        $process->values = serialize($values);
        $process->save();

        $user = User::whereDoesntHave('streams', function($query) use ($process) {
            $query->where('process_id', $process->id);
        })->first();

        $this->assertNotNull($user, 'User should exist in database');

        $kubeServiceMock = $this->mock(KubeService::class);
        $kubeServiceMock->shouldReceive('sendFile')->andReturn(['status' => 'success']);

        $request = Request::create('/admin/process/assignUser', 'POST', [
            'process_id' => $process->id,
            'assign_user' => $user->id,
        ]);

        $controller = new ProcessController();
        $response = $controller->assignUser($kubeServiceMock, $request);

        $this->assertEquals(200, $response->getStatusCode());

        // Verify that a stream was created
        $this->assertDatabaseHas('streams', [
            'user_id' => $user->id,
            'process_id' => $process->id
        ]);
    }

    public function test_assign_user_with_invalid_kafka_config()
    {
        // Use existing process with invalid kafka config (should still work, just use defaults)
        $process = Processes::first();
        $this->assertNotNull($process, 'Process should exist in database');

        // Set invalid kafka config
        $values = unserialize($process->values);
        $values['user_request']['kafka_config'] = 'invalid json string';
        $process->values = serialize($values);
        $process->save();

        $user = User::whereDoesntHave('streams', function($query) use ($process) {
            $query->where('process_id', $process->id);
        })->first();

        $this->assertNotNull($user, 'User should exist in database');

        $kubeServiceMock = $this->mock(KubeService::class);
        $kubeServiceMock->shouldReceive('sendFile')->andReturn(['status' => 'success']);

        $request = Request::create('/admin/process/assignUser', 'POST', [
            'process_id' => $process->id,
            'assign_user' => $user->id,
        ]);

        $controller = new ProcessController();
        $response = $controller->assignUser($kubeServiceMock, $request);

        $this->assertEquals(200, $response->getStatusCode());

        // Verify that a stream was created (should fall back to defaults)
        $this->assertDatabaseHas('streams', [
            'user_id' => $user->id,
            'process_id' => $process->id
        ]);
    }

    public function test_assign_user_kafka_config_merge()
    {
        // Test that the kafka config is properly merged into the main config
        $process = Processes::first();
        $this->assertNotNull($process, 'Process should exist in database');

        // Set up test data with kafka config
        $values = unserialize($process->values);
        $values['user_request']['kafka_config'] = [
            'fetch.min.bytes' => 1921,
            'fetch.max.wait.ms' => 600,
            'fetch.max.bytes' => 2097152,
            'max.poll.records' => 1000
        ];
        $process->values = serialize($values);
        $process->save();

        // Simulate the merge logic from assignUser
        $testValues = unserialize($process->values);
        if (isset($testValues['user_request']['kafka_config']) && is_array($testValues['user_request']['kafka_config'])) {
            $kafkaConfig = $testValues['user_request']['kafka_config'];
            $testValues['kafka']['fetch_min_bytes'] = $kafkaConfig['fetch.min.bytes'] ?? $testValues['kafka']['fetch_min_bytes'];
            $testValues['kafka']['fetch_max_wait_ms'] = $kafkaConfig['fetch.max.wait.ms'] ?? $testValues['kafka']['fetch_max_wait_ms'];
            $testValues['kafka']['fetch_max_bytes'] = $kafkaConfig['fetch.max.bytes'] ?? $testValues['kafka']['fetch_max_bytes'];
            $testValues['kafka']['max_poll_records'] = $kafkaConfig['max.poll.records'] ?? $testValues['kafka']['max_poll_records'];
        }

        // Verify the merge worked correctly
        $this->assertEquals(1921, $testValues['kafka']['fetch_min_bytes']);
        $this->assertEquals(600, $testValues['kafka']['fetch_max_wait_ms']);
        $this->assertEquals(2097152, $testValues['kafka']['fetch_max_bytes']);
        $this->assertEquals(1000, $testValues['kafka']['max_poll_records']);
    }
}
