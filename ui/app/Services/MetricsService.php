<?php

namespace App\Services;


use Prometheus\CollectorRegistry;

class MetricsService
{
    /** @var CollectorRegistry */
    protected static $instance;


    private function __construct()
    {
    }

    private function __clone()
    {
    }

    private function __wakeup()
    {
    }

    public static function getInstance()
    {
        if (is_null(self::$instance)) {
            self::$instance = \Prometheus\CollectorRegistry::getDefault();
        }

        return self::$instance;
    }

}
