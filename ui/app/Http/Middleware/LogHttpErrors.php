<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Log;
use Symfony\Component\HttpFoundation\Response;

class LogHttpErrors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {

        $response = $next($request);

        if ($response->getStatusCode() >= 400) {
            Log::error('HTTP Error', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'status' => $response->getStatusCode(),
                'body' => $response->getContent(),
                'request_data' => json_encode($request->getPayload()->all())
            ]);
        }

        return $response;
    }
}
