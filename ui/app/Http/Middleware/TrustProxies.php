<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Middleware\TrustProxies as Middleware;

class TrustProxies extends Middleware
{
    // Only the original scheme is needed for TLS-terminating ingress.
    protected $headers = Request::HEADER_X_FORWARDED_PROTO;

    protected function setTrustedProxyIpAddresses(Request $request)
    {
        $proxies = array_filter(
            array_map('trim', explode(',', (string) config('app.trusted_proxies', ''))),
            static function (string $proxy): bool {
                [$ip, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);

                return filter_var($ip, FILTER_VALIDATE_IP) !== false
                    && ($prefix === null || (ctype_digit($prefix) && (int) $prefix >= 1
                        && (int) $prefix <= (str_contains($ip, ':') ? 128 : 32)));
            }
        );

        // Do not fall back to Laravel's automatic trust-all proxy detection.
        $request->setTrustedProxies(array_values($proxies), $this->getTrustedHeaderNames());
    }
}
