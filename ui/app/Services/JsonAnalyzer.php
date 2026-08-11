<?php

namespace App\Services;

class JsonAnalyzer
{

    private $data;
    private $schema = [];

    private $tmpPath = [];

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function analyze()
    {
        foreach ($this->data as $datum) {
            $json = json_decode($datum, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $datum = ['document' => $datum];
            } else {
                $datum = $json;
            }

            $this->recursiveArrayWalk($datum);
        }

        ksort($this->schema);

        return [
            'allCount' => count($this->data),
            'schema'   => $this->schema
        ];
    }


    public function recursiveArrayWalk($arr)
    {
        foreach ($arr as $k => $value) {

            $this->tmpPath[] = $k;
            $path            = implode('.', $this->tmpPath);

            if (is_array($value)) {
                if (empty($value)){
                    array_pop($this->tmpPath);
                    continue;
                }
                if (isset($value[0])) {
                    $this->addToSchema($path, 'json[]', $value);
                } else {
                    $this->addToSchema($path, 'json', '');
                    $this->recursiveArrayWalk($value);
                }
            } else {
                $type = $this->checkType($value);
                $this->addToSchema($path, $type, $value);
            }
            array_pop($this->tmpPath);
        }
    }


    private function addToSchema($path, $type, $value)
    {
        if (isset($this->schema[$path])) {
            $this->schema[$path]['count']++;

            if (isset($this->schema[$path]['types'][$type])) {
                $this->schema[$path]['types'][$type]['count']++;
            } else {
                $this->schema[$path]['types'][$type] = [
                    'count'   => 1,
                    'example' => (is_array($value)?json_encode($value):$value)
                ];
            }
        } else {
            $this->schema[$path] = [
                'count' => 1,
                'types' => [
                    $type => [
                        'count'   => 1,
                        'example' => (is_array($value)?json_encode($value):$value)
                    ]
                ]
            ];
        }
    }

    private function checkType($data)
    {
        if ($data === true || $data === false || $data === 'true' || $data === 'false') {
            return 'bool';
        }

        if (filter_var($data, FILTER_VALIDATE_URL)){
            return 'url';
        }

        if (filter_var($data, FILTER_VALIDATE_INT) !== false) {
            if ($data > 946684800 && $data <= 1886709000) {
                return 'timestamp';
            }

            $numLength = strlen((string)$data);
            if ($numLength > 11) {
                return 'bigint';
            }

            return 'int';
        }


        if (filter_var($data, FILTER_VALIDATE_FLOAT) !== false) {
            return 'float';
        }

        return 'string';
    }
}
