<?php

namespace Tests\Feature\Routes;

use DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * @group application
 */
class AuthTest extends TestCase
{

    public function testIndexRedirect()
    {
        $response = $this->get('/');
        $response->assertStatus(302);
        $response->assertRedirect('/login');
    }


    public function testLogin()
    {

        $response = $this->get('/admin/home');
        $response->assertStatus(302);
        $response->assertRedirect('/login');

    }


    #[DataProvider('proxyLoginCases')]
    public function testLoginFormBehindProxy(string $remoteAddr, string $proxies, array $headers, string $scheme): void
    {
        config(['app.trusted_proxies' => $proxies]);

        $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->get('http://streams.example.test/login', $headers)
            ->assertOk()
            ->assertSee('action="' . $scheme . '://streams.example.test/login"', false);
    }

    public static function proxyLoginCases(): array
    {
        $forwarded = [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'other.example.test',
            'X-Forwarded-Port' => '8443',
        ];

        return [
            'direct HTTP' => ['192.0.2.10', '', [], 'http'],
            'no trusted proxies' => ['192.0.2.10', '', $forwarded, 'http'],
            'trusted IPv4' => ['192.0.2.10', '192.0.2.10', $forwarded, 'https'],
            'trusted IPv4 subnet' => ['192.0.2.10', ' 198.51.100.7, 192.0.2.0/24 ', $forwarded, 'https'],
            'trusted IPv6 subnet' => ['2001:db8::10', '2001:db8::/64', $forwarded, 'https'],
            'untrusted IP' => ['198.51.100.10', '192.0.2.0/24', $forwarded, 'http'],
            'wildcards and aliases ignored' => ['192.0.2.10', '*, **, REMOTE_ADDR, PRIVATE_SUBNETS, 0.0.0.0/0, ::/0', $forwarded, 'http'],
            'malformed subnet ignored' => ['192.0.2.10', '192.0.2.0/not-a-prefix', $forwarded, 'http'],
            'trusted proxy without forwarded scheme' => ['192.0.2.10', '192.0.2.10', [], 'http'],
        ];
    }

    public function testLogout()
    {

        $response = $this->get('/logout');
        $response->assertRedirect('/login');
        $response->assertStatus(302);
    }

    public function testForgotPassword()
    {
        $response = $this->get('password/reset');
        $response->assertSee('Reset Password');
    }
}
