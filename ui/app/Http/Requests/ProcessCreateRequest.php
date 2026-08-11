<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessCreateRequest extends FormRequest
{
    private const ATTR_TYPES = ['string', 'int', 'bigint', 'float', 'bool', 'timestamp', 'url', 'json'];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'id' => ['exists:processes,id'],
            'name' => ['required', 'string'],
            'source_id' => ['required', 'exists:sources,id'],
            'destination_id' => ['required', 'exists:destinations,id'],
            'language' => ['required', 'string'],
            'output_docs' => ['required', 'string', 'regex:/[0-1]{4}/'],
            'max_batch_size' => ['required', 'integer', 'min:1'],
            'min_threads' => ['integer', 'min:1'],
            'max_threads' => ['required', 'integer', 'min:1'],
            'nlp_settings' => ['string', 'nullable'],
            'attrs' => ['required', 'string'],
            // Kafka configuration validation - handle both individual fields and kafka_config JSON
            'kafka_config' => ['string', 'nullable'],
            'fetch_min_bytes' => ['integer', 'min:1'],
            'fetch_max_wait_ms' => ['integer', 'min:0'],
            'fetch_max_bytes' => ['integer', 'min:1'],
            'max_poll_records' => ['integer', 'min:1'],
            // Query complexity validation
            'query_complexity_validation' => ['boolean'],
            'max_matches_percent' => ['required_if:query_complexity_validation,true', 'integer', 'min:0', 'max:100'],
            // JSLT configuration
            'jslt_conf' => ['string', 'nullable'],
            // Searchd settings
            'searchd_settings' => ['string', 'nullable'],
            // Stopwords and exceptions for custom language
            'stopwords' => ['string', 'nullable'],
            'exceptions' => ['string', 'nullable'],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->has('attrs') && $attrs = $this->get('attrs')) {
                if ($attrs !== null) {
                    $attributes = json_decode(urldecode($attrs), true);
                    foreach ($attributes as $attribute) {
                        $this->checkAttribute($attribute, $validator);
                    }
                }
            }

            // Additional validation for custom language settings
            if ($this->get('language') === 'custom') {
                if ($this->filled('nlp_settings') && trim($this->get('nlp_settings')) === '') {
                    $validator->errors()->add('nlp_settings', 'NLP settings cannot be empty when using custom language');
                }
            }


            

            // Validate kafka_config JSON field
            if ($this->has('kafka_config') && !empty($this->get('kafka_config'))) {
                // Handle double URL encoding
                $kafkaConfigJson = urldecode(urldecode($this->get('kafka_config')));
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
        });
    }


    private function checkAttribute($attribute, $validator)
    {
        foreach (['name', 'path', 'type'] as $key) {
            if (!isset($attribute[$key])) {
                $validator->errors()->add('attrs', 'Attribute attr[].'.$key.' is mandatory');
            } elseif ($key === 'name' && !preg_match('/[a-z0-9\-_]/ui', $attribute[$key])) {
                $validator->errors()->add(
                    'attrs',
                    'Attribute attr[].'.$attribute[$key].' has wrong name. Allowed regex: [a-z0-9\-_]'
                );
            } elseif ($key === 'type' && !in_array($attribute[$key], self::ATTR_TYPES)) {
                $validator->errors()->add(
                    'attrs',
                    'Attribute attr[].'.$key.' has wrong type. Allowed types: '.implode('|', self::ATTR_TYPES)
                );
            } elseif ($key === 'path') {

                $path = $attribute[$key];
                if (strpos($path, '&amp;') !== false){
                    $path = str_replace('&amp;', '&', $path);
                }

                foreach (explode('&&', $path) as $mergedNode) {
                    foreach (explode(".", $mergedNode) as $node) {
                        if (!preg_match('/^[a-z0-9_\-]+(\[\*\])?$/usi', $node)) {
                            $validator->errors()->add(
                                'attrs',
                                'Non allowed symbols in node "'.$node.'" Full path: "'.$attribute[$key].'"'
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Get the parsed Kafka configuration from the kafka_config field
     *
     * @return array
     */
    public function getKafkaConfig()
    {
        // First try to get values from individual fields (legacy support)
        $config = [
            'fetch_min_bytes' => $this->get('fetch_min_bytes', 1),
            'fetch_max_wait_ms' => $this->get('fetch_max_wait_ms', 500),
            'fetch_max_bytes' => $this->get('fetch_max_bytes', 1048576),
            'max_poll_records' => $this->get('max_poll_records', 500),
        ];

        // If kafka_config JSON field is provided, use those values instead
        if ($this->has('kafka_config') && !empty($this->get('kafka_config'))) {
            // Handle double URL encoding
            $kafkaConfigJson = urldecode(urldecode($this->get('kafka_config')));
            $parsedKafkaConfig = json_decode($kafkaConfigJson, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($parsedKafkaConfig)) {
                $config = [
                    'fetch_min_bytes' => $parsedKafkaConfig['fetch.min.bytes'] ?? 1,
                    'fetch_max_wait_ms' => $parsedKafkaConfig['fetch.max.wait.ms'] ?? 500,
                    'fetch_max_bytes' => $parsedKafkaConfig['fetch.max.bytes'] ?? 1048576,
                    'max_poll_records' => $parsedKafkaConfig['max.poll.records'] ?? 500,
                ];
            }
        }

        return $config;
    }
}
