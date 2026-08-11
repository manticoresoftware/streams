<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Requests\ProcessCreateRequest;
use App\Models\Source;
use App\Models\Destination;

/**
 * ProcessCreateRequest tests
 *
 * @group application
 */
class ProcessCreateRequestTest extends TestCase
{

    public function test_kafka_config_validation_with_valid_data()
    {
        $source = Source::first();
        $destination = Destination::first();

        $this->assertNotNull($source, 'Source should exist in database');
        $this->assertNotNull($destination, 'Destination should exist in database');

        $validData = [
            'name' => 'Test Process',
            'source_id' => $source->id,
            'destination_id' => $destination->id,
            'language' => 'chinese',
            'output_docs' => '0010',
            'max_batch_size' => 5000,
            'min_threads' => 1,
            'max_threads' => 3,
            'attrs' => '[]',
            'kafka_config' => json_encode([
                'fetch.min.bytes' => 1000,
                'fetch.max.wait.ms' => 500,
                'fetch.max.bytes' => 1048576,
                'max.poll.records' => 500
            ])
        ];

        $request = new ProcessCreateRequest();
        $request->merge($validData);

        $validator = validator($validData, $request->rules());

        // Manually trigger the custom validation logic from withValidator
        $validator->after(function ($validator) use ($request) {
            if ($request->has('kafka_config') && !empty($request->get('kafka_config'))) {
                $kafkaConfigJson = urldecode(urldecode($request->get('kafka_config')));
                $kafkaConfig = json_decode($kafkaConfigJson, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $validator->errors()->add('kafka_config', 'Invalid JSON format for Kafka configuration');
                } elseif (!is_array($kafkaConfig)) {
                    $validator->errors()->add('kafka_config', 'Kafka configuration must be a valid JSON object');
                } else {
                    // Validate individual Kafka configuration values
                    $fetchMinBytes = $kafkaConfig['fetch.min.bytes'] ?? null;
                    $fetchMaxWaitMs = $kafkaConfig['fetch.max.wait.ms'] ?? null;
                    $fetchMaxBytes = $kafkaConfig['fetch.max.bytes'] ?? null;
                    $maxPollRecords = $kafkaConfig['max.poll.records'] ?? null;

                    if ($fetchMinBytes !== null && (!is_int($fetchMinBytes) || $fetchMinBytes < 1)) {
                        $validator->errors()->add('kafka_config', 'fetch.min.bytes must be an integer >= 1');
                    }
                    if ($fetchMaxWaitMs !== null && (!is_int($fetchMaxWaitMs) || $fetchMaxWaitMs < 0)) {
                        $validator->errors()->add('kafka_config', 'fetch.max.wait.ms must be an integer >= 0');
                    }
                    if ($fetchMaxBytes !== null && (!is_int($fetchMaxBytes) || $fetchMaxBytes < 1)) {
                        $validator->errors()->add('kafka_config', 'fetch.max.bytes must be an integer >= 1');
                    }
                    if ($maxPollRecords !== null && (!is_int($maxPollRecords) || $maxPollRecords < 1)) {
                        $validator->errors()->add('kafka_config', 'max.poll.records must be an integer >= 1');
                    }
                }
            }

            // Validate optional string fields are not empty if provided
            $optionalStringFields = ['searchd_settings', 'jslt_conf', 'stopwords', 'exceptions'];
            foreach ($optionalStringFields as $field) {
                if ($request->filled($field) && trim($request->get($field)) === '') {
                    $validator->errors()->add($field, ucfirst(str_replace('_', ' ', $field)) . ' cannot be empty if provided');
                }
            }
        });

        $this->assertTrue($validator->passes());
    }

    public function test_optional_string_fields_accept_null()
    {
        $source = Source::first();
        $destination = Destination::first();

        $this->assertNotNull($source, 'Source should exist in database');
        $this->assertNotNull($destination, 'Destination should exist in database');

        $baseData = [
            'name' => 'Test Process',
            'source_id' => $source->id,
            'destination_id' => $destination->id,
            'language' => 'chinese',
            'output_docs' => '0010',
            'max_batch_size' => 5000,
            'min_threads' => 1,
            'max_threads' => 3,
            'attrs' => '[]',
        ];

        $optionalFields = ['searchd_settings', 'jslt_conf', 'stopwords', 'exceptions'];

        foreach ($optionalFields as $field) {
            $data = $baseData;
            $data[$field] = null;

            $request = new ProcessCreateRequest();
            $request->merge($data);

            $validator = validator($data, $request->rules());

            $this->assertTrue($validator->passes(), "Validation should pass for null $field");
        }
    }

    public function test_optional_string_fields_accept_empty_strings()
    {
        $source = Source::first();
        $destination = Destination::first();

        $this->assertNotNull($source, 'Source should exist in database');
        $this->assertNotNull($destination, 'Destination should exist in database');

        $baseData = [
            'name' => 'Test Process',
            'source_id' => $source->id,
            'destination_id' => $destination->id,
            'language' => 'chinese',
            'output_docs' => '0010',
            'max_batch_size' => 5000,
            'min_threads' => 1,
            'max_threads' => 3,
            'attrs' => '[]',
        ];

        $optionalFields = ['searchd_settings', 'jslt_conf', 'stopwords', 'exceptions'];

        foreach ($optionalFields as $field) {
            $data = $baseData;
            $data[$field] = '';

            $request = new ProcessCreateRequest();
            $request->merge($data);

            $validator = validator($data, $request->rules());

            $this->assertTrue($validator->passes(), "Validation should pass for empty string $field");
        }
    }

    public function test_optional_string_fields_accept_valid_strings()
    {
        $source = Source::first();
        $destination = Destination::first();

        $this->assertNotNull($source, 'Source should exist in database');
        $this->assertNotNull($destination, 'Destination should exist in database');

        $baseData = [
            'name' => 'Test Process',
            'source_id' => $source->id,
            'destination_id' => $destination->id,
            'language' => 'chinese',
            'output_docs' => '0010',
            'max_batch_size' => 5000,
            'min_threads' => 1,
            'max_threads' => 3,
            'attrs' => '[]',
        ];

        $optionalFields = ['searchd_settings', 'nlp_settings', 'jslt_conf', 'stopwords', 'exceptions'];

        foreach ($optionalFields as $field) {
            $data = $baseData;
            $data[$field] = 'valid string';

            $request = new ProcessCreateRequest();
            $request->merge($data);

            $validator = validator($data, $request->rules());

            $this->assertTrue($validator->passes(), "Validation should pass for valid string $field");
        }
    }

    public function test_get_kafka_config_returns_defaults_when_no_config_provided()
    {
        $source = Source::first();
        $destination = Destination::first();

        $this->assertNotNull($source, 'Source should exist in database');
        $this->assertNotNull($destination, 'Destination should exist in database');

        $data = [
            'name' => 'Test Process',
            'source_id' => $source->id,
            'destination_id' => $destination->id,
            'language' => 'chinese',
            'output_docs' => '0010',
            'max_batch_size' => 5000,
            'min_threads' => 1,
            'max_threads' => 3,
            'attrs' => '[]',
        ];

        $request = new ProcessCreateRequest();
        $request->merge($data);

        $kafkaConfig = $request->getKafkaConfig();

        $this->assertEquals([
            'fetch_min_bytes' => 1,
            'fetch_max_wait_ms' => 500,
            'fetch_max_bytes' => 1048576,
            'max_poll_records' => 500,
        ], $kafkaConfig);
    }

    public function test_get_kafka_config_returns_custom_values_when_json_provided()
    {
        $source = Source::first();
        $destination = Destination::first();

        $this->assertNotNull($source, 'Source should exist in database');
        $this->assertNotNull($destination, 'Destination should exist in database');

        $customKafkaConfig = [
            'fetch.min.bytes' => 1921,
            'fetch.max.wait.ms' => 600,
            'fetch.max.bytes' => 2097152,
            'max.poll.records' => 1000
        ];

        $data = [
            'name' => 'Test Process',
            'source_id' => $source->id,
            'destination_id' => $destination->id,
            'language' => 'chinese',
            'output_docs' => '0010',
            'max_batch_size' => 5000,
            'min_threads' => 1,
            'max_threads' => 3,
            'attrs' => '[]',
            'kafka_config' => urlencode(json_encode($customKafkaConfig))
        ];

        $request = new ProcessCreateRequest();
        $request->merge($data);

        $kafkaConfig = $request->getKafkaConfig();

        $this->assertEquals([
            'fetch_min_bytes' => 1921,
            'fetch_max_wait_ms' => 600,
            'fetch_max_bytes' => 2097152,
            'max_poll_records' => 1000,
        ], $kafkaConfig);
    }

    public function test_get_kafka_config_falls_back_to_defaults_for_partial_json_config()
    {
        $source = Source::first();
        $destination = Destination::first();

        $this->assertNotNull($source, 'Source should exist in database');
        $this->assertNotNull($destination, 'Destination should exist in database');

        // Partial config - only one field provided
        $partialKafkaConfig = [
            'fetch.min.bytes' => 1921,
        ];

        $data = [
            'name' => 'Test Process',
            'source_id' => $source->id,
            'destination_id' => $destination->id,
            'language' => 'chinese',
            'output_docs' => '0010',
            'max_batch_size' => 5000,
            'min_threads' => 1,
            'max_threads' => 3,
            'attrs' => '[]',
            'kafka_config' => urlencode(json_encode($partialKafkaConfig))
        ];

        $request = new ProcessCreateRequest();
        $request->merge($data);

        $kafkaConfig = $request->getKafkaConfig();

        // Should have custom value for fetch_min_bytes, defaults for others
        $this->assertEquals([
            'fetch_min_bytes' => 1921,
            'fetch_max_wait_ms' => 500,
            'fetch_max_bytes' => 1048576,
            'max_poll_records' => 500,
        ], $kafkaConfig);
    }
}