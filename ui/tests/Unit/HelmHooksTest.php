<?php

namespace Tests\Unit;

use App\Models\Streams;
use App\Models\Variable;
use App\Services\Curl\CurlService;
use App\Services\Curl\KubeService;
use Mockery;
use Mockery\MockInterface;
use Schema;
use Tests\TestCase;
use Tests\Traits\AuthTrait;

/**
 * @group application
 */
class HelmHooksTest extends TestCase
{
    use AuthTrait;

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }


    private function mockCurl(\Closure $closure)
    {
        $this->instance(
            KubeService::class,
            Mockery::mock(KubeService::class, $closure)
        );
    }


    /**
     * @test
     */

    public function appendToHelm(){
        Streams::factory()->create();

        $streamsCount = Streams::count();
        $this->mockCurl(function (MockInterface $mock) use ($streamsCount){

            $mock->shouldReceive('sendFile')->times($streamsCount * 2)->andReturn(['status' => CurlService::STATUS_SUCCESS, 'result' => []]);
        });


        $this->artisan('chart:post-upgrade')->assertExitCode(0);
    }

    /**
     * @test
     */

    public function deleteHelm(){

        Schema::disableForeignKeyConstraints();
        Variable::truncate();
        Streams::truncate();
        Schema::enableForeignKeyConstraints();

        Streams::factory()->create();

        $streams = Streams::all();


        $this->mockCurl(function (MockInterface $mock) use ($streams){

            foreach ($streams as $stream){
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
                            'status' => CurlService::STATUS_SUCCESS, 'result' => [
                                'items' =>
                                    [[
                                        'metadata' =>
                                            [
                                                'labels' => ['streamID' => $stream->id],
                                                'name'   => 'name',
                                            ],
                                    ]],
                            ],
                        ]);
                }

            }

            $mock->shouldReceive('remove')->times(4)->andReturn([ 'status' => CurlService::STATUS_SUCCESS]);

        });

        $this->artisan('chart:delete')->assertExitCode(0);
    }
}
