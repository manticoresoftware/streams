<?php

class Curl
{
    const string STATUS_SUCCESS = 'success';
    const string STATUS_ERROR = 'error';
    private array $errors = [];

    private string $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 ' .
    '(KHTML, like Gecko) Chrome/71.0.3578.98 Safari/537.36';
    private $bearer = null;
    private $cert = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';

    public function __construct()
    {
        $this->bearer = $this->getBearer();
    }


    public function get($url)
    {
        return $this->curlRequest([CURLOPT_URL => $url,
                                   CURLOPT_HTTPHEADER => [
                                       'Authorization: Bearer ' . $this->bearer,
                                       'Accept: application/json'
                                   ]]);
    }

    public function post($url, $content)
    {
        if (is_array($content)) {
            $content = http_build_query($content);
        }

        return $this->curlRequest([
            CURLOPT_URL        => $url,
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json'
            ]
        ]);
    }

    public function replaceRequest($url, $content)
    {
        return $this->curlRequest([
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => "PATCH",
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->bearer,
                'Accept: application/json',
                'Content-Type: application/json-patch+json'
            ]
        ]);
    }


    public function getErrors()
    {
        return $this->errors;
    }


    private function getBearer()
    {
        $bearerFile = '/var/run/secrets/kubernetes.io/serviceaccount/token';
        if (file_exists($bearerFile)) {
            return file_get_contents($bearerFile);
        }

        return false;
    }

    private function curlRequest($options)
    {
        $this->errors = [];
        $ch = curl_init();

        $defaultOptions = [
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => $this->cert,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->bearer,
                'Accept: application/json',
                'Content-Type: application/yaml'
            ],
        ];

        $defaultOptions = $options + $defaultOptions;

        curl_setopt_array($ch, $defaultOptions);

        $serverOutput = curl_exec($ch);

        if (!curl_errno($ch)) {

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (!in_array($httpCode, [200, 201, 202])) {

                $this->errors[] = date('Y-m-d H:i:s') . ': Request error. Status = ' . $httpCode . ", <br> Response: " . $serverOutput;
                return ['status' => self::STATUS_ERROR, 'message' => 'Request error. Status = ' . $httpCode];
            }


        } else {
            $this->errors[] = date('Y-m-d H:i:s') . ': Curl error. ' . curl_error($ch) ;
            return ['status' => self::STATUS_ERROR, 'message' => 'Curl error. ' . curl_error($ch)];
        }

        curl_close($ch);

        return ['status' => self::STATUS_SUCCESS, 'result' => json_decode($serverOutput, true)];
    }

}
