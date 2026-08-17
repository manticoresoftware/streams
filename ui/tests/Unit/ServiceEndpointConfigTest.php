<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * @group application
 */
class ServiceEndpointConfigTest extends TestCase
{
    public function testManticoreConfigFallsBackToComposeEndpointWithoutExplicitHost(): void
    {
        $host = getenv('MANTICORE_HOST');
        $port = getenv('MANTICORE_PORT');

        try {
            putenv('MANTICORE_HOST');
            putenv('MANTICORE_PORT');

            $config = require base_path('config/manticore.php');

            self::assertSame('manticore:9306', $config['host']);
        } finally {
            putenv('MANTICORE_HOST'.($host === false ? '' : '='.$host));
            putenv('MANTICORE_PORT'.($port === false ? '' : '='.$port));
        }
    }

    public function testManticoreConfigPrefersExplicitEndpointInTesting(): void
    {
        $host = getenv('MANTICORE_HOST');
        $port = getenv('MANTICORE_PORT');

        try {
            putenv('MANTICORE_HOST=manticore-dev-tests');
            putenv('MANTICORE_PORT=9306');

            $config = require base_path('config/manticore.php');

            self::assertSame('manticore-dev-tests:9306', $config['host']);
        } finally {
            putenv('MANTICORE_HOST'.($host === false ? '' : '='.$host));
            putenv('MANTICORE_PORT'.($port === false ? '' : '='.$port));
        }
    }

    public function testColumnarConfigPrefersExplicitEndpointInTesting(): void
    {
        $host = getenv('COLUMNAR_HOST');
        $port = getenv('COLUMNAR_PORT');

        try {
            putenv('COLUMNAR_HOST=columnar-dev-tests');
            putenv('COLUMNAR_PORT=9306');

            $config = require base_path('config/columnar.php');

            self::assertSame('columnar-dev-tests:9306', $config['host']);
        } finally {
            putenv('COLUMNAR_HOST'.($host === false ? '' : '='.$host));
            putenv('COLUMNAR_PORT'.($port === false ? '' : '='.$port));
        }
    }

    public function testColumnarConfigFallsBackToComposeEndpointWithoutExplicitHost(): void
    {
        $host = getenv('COLUMNAR_HOST');
        $port = getenv('COLUMNAR_PORT');

        try {
            putenv('COLUMNAR_HOST');
            putenv('COLUMNAR_PORT');

            $config = require base_path('config/columnar.php');

            self::assertSame('columnar:9306', $config['host']);
        } finally {
            putenv('COLUMNAR_HOST'.($host === false ? '' : '='.$host));
            putenv('COLUMNAR_PORT'.($port === false ? '' : '='.$port));
        }
    }
}
