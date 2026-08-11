<?php

/**
 * @property $threads
 * @property $batchSize
 */

class Response
{
    private $responseValues = ['status' => 'success'];

    public function printResponse($code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($this->responseValues);
    }

    public function __set($name, $value)
    {
        $this->responseValues[$name] = $value;
    }
}
