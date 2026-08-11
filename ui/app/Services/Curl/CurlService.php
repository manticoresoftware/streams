<?php

namespace App\Services\Curl;


class CurlService
{
    const STATUS_SUCCESS = 'success';
    const STATUS_ERROR = 'error';

    private $error = '';


    public function __construct()
    {
        $this->userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '.
            '(KHTML, like Gecko) Chrome/71.0.3578.98 Safari/537.36';
    }

    public function get($url)
    {
        return $this->curlRequest([
            CURLOPT_URL        => $url,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);
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
                'Accept: application/json',
            ],
        ]);
    }

    public function remove($url)
    {
        return $this->curlRequest([
            CURLOPT_URL           => $url,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
        ]);
    }


    /**
     * @param  string  $url
     * @param $file
     *
     * @return array
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function sendFile($url, $content)
    {
        return $this->curlRequest([
            CURLOPT_URL        => $url,
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $content,
        ]);
    }

    public function replaceRequest($url, $content)
    {
        return $this->curlRequest([
            CURLOPT_URL           => $url,
            CURLOPT_CUSTOMREQUEST => "PUT",
            CURLOPT_POSTFIELDS    => $content,
            CURLOPT_HTTPHEADER    => [
                'Accept: application/json',
                'Content-Type: application/yaml',
            ],
        ]);
    }

    public function patchRequest($url, $content)
    {
        return $this->curlRequest([
            CURLOPT_URL           => $url,
            CURLOPT_CUSTOMREQUEST => "PATCH",
            CURLOPT_POSTFIELDS    => $content,
            CURLOPT_HTTPHEADER    => [
                'Accept: application/json',
                'Content-Type: application/strategic-merge-patch+json',
            ],
        ]);
    }


    public function getError()
    {
        return $this->error;
    }


    private function curlRequest($options)
    {
        $this->error = [];
        $ch          = curl_init();

        $defaultOptions = $this->getDefaultOptions();

        $defaultHeaders = $defaultOptions[CURLOPT_HTTPHEADER];

        $defaultOptions = $options + $defaultOptions;

        if (!empty($options[CURLOPT_HTTPHEADER])){
            foreach ($defaultHeaders as $header){
                $defaultOptions[CURLOPT_HTTPHEADER][] = $header;
            }
        }

        curl_setopt_array($ch, $defaultOptions);

        $serverOutput = curl_exec($ch);

        if ( ! curl_errno($ch)) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ( ! in_array($httpCode, [200, 201, 202])) {
                $this->error = date('Y-m-d H:i:s').': Request error. Status = '.$httpCode.", <br> Response: ".$serverOutput;

                return [
                    'status'  => self::STATUS_ERROR,
                    'message' => 'Request error. Status = '.$httpCode.", <br> Response: ".$serverOutput,
                ];
            }
        } else {
            $this->error = date('Y-m-d H:i:s').': Curl error. '.curl_error($ch);

            return ['status' => self::STATUS_ERROR, 'message' => 'Curl error. '.curl_error($ch)];
        }

        curl_close($ch);

        if ($this->error) {
            \Log::debug("Curl errors: ".print_r($this->error, true));
        }


        return ['status' => self::STATUS_SUCCESS, 'result' => json_decode($serverOutput, true)];
    }

    protected function getDefaultOptions(): array
    {
        return [
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_ENCODING       => 'gzip, deflate',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/yaml',
            ],
        ];
    }
}
