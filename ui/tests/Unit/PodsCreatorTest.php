<?php

namespace Tests\Unit;

use App\Services\Curl\KubeService;
use App\Services\StreamsService;
use App\Services\Curl\CurlService;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Mockery;
use Storage;
use Tests\TestCase;
use Tests\Traits\AuthTrait;
use Throwable;


/**
 * @group application
 */
class PodsCreatorTest extends TestCase
{
    use AuthTrait;

    private $defaultValues = [
        'kafka'        =>
            [
                'inputHost'   => 'localhost:29092',
                'outputHost'  => 'dev.manticoresearch.com:21',
                'inputTopic'  => 'my-docs',
                'outputTopic' => 'out.{username}',
                'groupName'   => 'MKC_dolores',
            ],
        'worker'       =>
            [
                'outputDocs'   => '1000',
                'handlerRules' => 'whole_document => whole_document',
                'maxThreads'   => 3,
                'maxBatchSize' => 5000,
            ],
        'rulesChecker' =>
            [
                'enabled'           => true,
                'maxMatchedPercent' => 20,
            ],
        'manticore'    =>
            [
                'configAdditiveFields' => 'json=whole_document'
            ],
    ];

    protected $curl;


    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $this->curl = Mockery::mock(KubeService::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Create proper model instances for testing
        $user = \App\Models\User::first();
        $process = \App\Models\Processes::first();

        if (!$user || !$process) {
            $this->markTestSkipped('User or Process not found. Run DatabaseTest first.');
        }

        $this->stream = \App\Models\Streams::where('user_id', $user->id)
                                          ->where('process_id', $process->id)
                                          ->first();

        if (!$this->stream) {
            $this->stream = \App\Models\Streams::create([
                'user_id' => $user->id,
                'process_id' => $process->id,
                'stopped' => 0
            ]);
        }
    }

    /**
     * @throws Throwable
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    /**
     * @dataProvider urlProvider
     *
     * @param $type
     * @param $expected
     */

    public function testPrepareUrl($type, $expected): void
    {

        $context = new class($this->curl, $this->stream, $this->defaultValues) extends StreamsService {

            public function check($type)
            {
                return $this->prepareUrl($type);
            }
        };


        $prepared = $context->check($type);

        self::assertSame($prepared, $expected);
    }

    public static function urlProvider(): array
    {
        return [
            [
                'horizontalpodautoscalers',
                'https://kubernetes.default.svc/apis/autoscaling/v2beta1/namespaces/manticore-streams/horizontalpodautoscalers'
            ],
            ['configmaps', 'https://kubernetes.default.svc/api/v1/namespaces/manticore-streams/configmaps'],
            ['deployments', 'https://kubernetes.default.svc/apis/apps/v1/namespaces/manticore-streams/deployments'],
            ['services', 'https://kubernetes.default.svc/api/v1/namespaces/manticore-streams/services'],
            ['statefulsets', 'https://kubernetes.default.svc/apis/apps/v1/namespaces/manticore-streams/statefulsets'],
            ['persistentvolumeclaims', 'https://kubernetes.default.svc/api/v1/namespaces/manticore-streams/persistentvolumeclaims'],
            ['secrets', 'https://kubernetes.default.svc/api/v1/namespaces/manticore-streams/secrets']
        ];
    }


    /**
     * @throws FileNotFoundException
     */
    public function testSubstituteContent(): void
    {
        $contextService = new class($this->curl, $this->stream, $this->defaultValues) extends StreamsService {

            public function check($content): string
            {
                return $this->substituteContent($content);
            }
        };

        $content = Storage::disk(StreamsService::YAML_SOURCE)->get('stateful-set-pipeline.yaml');

        $substitutedContent = $contextService->check($content);

        // Check that placeholders are replaced with actual values
        self::assertFalse(strpos($substitutedContent, '{{ RELEASE_NAME }}'), 'RELEASE_NAME placeholder should be replaced');
        self::assertFalse(strpos($substitutedContent, '{{ STREAM_ID }}'), 'STREAM_ID placeholder should be replaced');
        self::assertFalse(strpos($substitutedContent, '{{ NAMESPACE }}'), 'NAMESPACE placeholder should be replaced');
        self::assertFalse(strpos($substitutedContent, '{{ FULLNAME }}'), 'FULLNAME placeholder should be replaced');

        // Check that specific values are present
        self::assertTrue(strpos($substitutedContent, 'manticore-streams') !== false, 'Namespace should be substituted');
        self::assertTrue(strpos($substitutedContent, 'm'.$this->stream->id) !== false, 'Stream ID should be substituted');
        self::assertTrue(strpos($substitutedContent, 'localhost:29092') !== false, 'Kafka input host should be present');
        self::assertTrue(strpos($substitutedContent, 'my-docs') !== false, 'Kafka input topic should be present');

        // Check that Values placeholders are replaced
        self::assertFalse(strpos($substitutedContent, '{{ Values.'), 'Values placeholders should be replaced');
    }

    public function testPrepareYaml(): void
    {
        $name           = 'stateful-set-pipeline.yaml';
        $contextService = new class($this->curl, $this->stream, $this->defaultValues) extends StreamsService {

            public function check($fileName)
            {
                return $this->generateResource($fileName);
            }
        };

        $yaml = $contextService->check($name);

        // Check that the result is properly structured
        self::assertIsArray($yaml, 'Result should be an array');
        self::assertArrayHasKey('type', $yaml, 'Result should have type key');
        self::assertArrayHasKey('content', $yaml, 'Result should have content key');
        self::assertEquals('statefulsets', $yaml['type'], 'Type should be statefulsets');

        // Parse the YAML content to check its structure
        $yamlContent = \Symfony\Component\Yaml\Yaml::parse($yaml['content']);

        // Check metadata
        self::assertEquals('StatefulSet', $yamlContent['kind'], 'Kind should be StatefulSet');
        self::assertTrue(strpos($yamlContent['metadata']['name'], 'm'.$this->stream->id) !== false, 'Name should contain stream ID');
        self::assertEquals('manticore-streams', $yamlContent['metadata']['namespace'], 'Namespace should be set');

        // Check spec structure
        self::assertArrayHasKey('spec', $yamlContent, 'Should have spec section');
        self::assertArrayHasKey('template', $yamlContent['spec'], 'Should have template in spec');
        self::assertArrayHasKey('spec', $yamlContent['spec']['template'], 'Should have spec in template');

        // Check containers
        $containers = $yamlContent['spec']['template']['spec']['containers'];
        self::assertGreaterThan(0, count($containers), 'Should have at least one container');

        // Check that environment variables are set correctly
        $workerContainer = null;
        foreach ($containers as $container) {
            if (isset($container['name']) && strpos($container['name'], 'worker') !== false) {
                $workerContainer = $container;
                break;
            }
        }

        self::assertNotNull($workerContainer, 'Should have a worker container');
        self::assertArrayHasKey('env', $workerContainer, 'Worker container should have env vars');

        // Check specific environment variables
        $envVars = array_column($workerContainer['env'], 'name');
        self::assertContains('KAFKA_INPUT_HOST', $envVars, 'Should have KAFKA_INPUT_HOST env var');
        self::assertContains('KAFKA_INPUT_TOPIC', $envVars, 'Should have KAFKA_INPUT_TOPIC env var');
        self::assertContains('LABEL', $envVars, 'Should have LABEL env var');
    }

    public function testMakeContext(): void
    {

        $this->curl->shouldReceive('sendFile')
                   ->times(7)
                   ->andReturn(['status' => CurlService::STATUS_SUCCESS]);


        $contextService = new class ($this->curl, $this->stream, $this->defaultValues) extends StreamsService{
            public function getGeneratedResourcesCount(): int
            {
                return count($this->generatedResources);
            }
        };
        $contextService->makeStream();
        self::assertSame(2, $contextService->getGeneratedResourcesCount());
    }

    public function testMakeConfigmap(): void
    {
        $this->curl = Mockery::mock(KubeService::class);
        $this->curl->shouldReceive('sendFile')->once()
            ->andReturn(['status' => CurlService::STATUS_SUCCESS]);

        $streamsService = new StreamsService($this->curl, $this->stream, $this->defaultValues);
        $streamsService->setProcessId(1);
        $streamsService->createConfigmap("testname");
    }

    public function testRemoveConfigmap(): void
    {
        $this->curl = Mockery::mock(KubeService::class);
        $this->curl->shouldReceive('remove')->once()
            ->andReturn(['status' => CurlService::STATUS_SUCCESS]);

        $streamsService = new StreamsService($this->curl, $this->stream, []);

        $streamsService->removeConfigmap("configmap-p1");
    }
}
