<?php

namespace App\Services\Curl;

use App;

class KubeService extends CurlService
{
    private $cert = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';

    /**
     * @throws \Exception
     */
    protected function getDefaultOptions(): array
    {
        $default = parent::getDefaultOptions();


        $default[CURLOPT_CAINFO]       = $this->cert;
        $default[CURLOPT_HTTPHEADER] = array_merge($default[CURLOPT_HTTPHEADER], $this->getBearerHeader());

        return $default;
    }

    /**
     * @throws \Exception
     */
    private function getBearerHeader(): array
    {
        $bearerFile = '/var/run/secrets/kubernetes.io/serviceaccount/token';
        if (file_exists($bearerFile)) {
            return ['Authorization: Bearer '.file_get_contents($bearerFile)];
        }

        if (App::environment(['dev','testing'])) {
            return [];
            //return ['Authorization: Bearer abc'];
        }
        throw new \Exception("Can't read k8s serviceaccount token");
    }
}
