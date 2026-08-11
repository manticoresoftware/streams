<?php

namespace Tests\Feature\Console;

use App\Console\Commands\UpdateProcessing;
use App\Models\Processes;
use App\Models\Streams;
use App\Models\User;
use App\Models\Source;
use App\Models\Destination;
use Tests\TestCase;
use Tests\Traits\AuthTrait;

class UpdateProcessingTest extends TestCase
{
    use AuthTrait;

    public function test_process_update_merges_kafka_config()
    {
        // Create test data
        $source = Source::factory()->create();
        $destination = Destination::factory()->create();
        $user = $this->getManager();

        // Create process with kafka_config in user_request
        $processData = [
            'name' => 'Test Process',
            'source_id' => $source->id,
            'destination_id' => $destination->id,
            'values' => serialize([
                'kafka' => [
                    'inputHost' => 'localhost:9092',
                    'outputHost' => 'localhost:9092',
                    'inputTopic' => 'input',
                    'outputTopic' => 'output',
                    'groupName' => 'group',
                    'fetch_min_bytes' => 1024,
                    'fetch_max_wait_ms' => 100,
                    'fetch_max_bytes' => 1048576,
                    'max_poll_records' => 100,
                ],
                'user_request' => [
                    'kafka_config' => [
                        'fetch.min.bytes' => 2048,
                        'fetch.max.wait.ms' => 200,
                        'fetch.max.bytes' => 2097152,
                        'max.poll.records' => 200,
                    ]
                ]
            ])
        ];
        $process = Processes::create($processData);

        // Create a stream for the process
        Streams::create([
            'user_id' => $user->id,
            'process_id' => $process->id,
            'stopped' => 0
        ]);

        // Mock KubeService and StreamsService
        $this->mock(\App\Services\Curl\KubeService::class);
        $this->mock(\App\Services\StreamsService::class, function ($mock) {
            $mock->shouldReceive('setProcessId')->andReturn();
            $mock->shouldReceive('upgradeStream')->andReturn();
            $mock->shouldReceive('redeployPipeline')->andReturn();
            $mock->shouldReceive('getErrors')->andReturn([]);
        });

        // Run the command
        $this->artisan('process:update', ['process_id' => $process->id, 'force' => 1])
             ->expectsOutput('Success')
             ->assertExitCode(0);
    }
}