<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Services\Curl\CurlService;
use Closure;

class HasStreamsMiddelware
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @param $role
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (\Request::route()->getName() !== 'emptyAssigns' && $request->user()->hasRole(Role::ROLE_MANAGER)) {
            if ($request->user()->streams()->get()->isEmpty()) {
                return $this->redirectEmptyAssigns($request);
            }

            if ($request->has('streamId') && ! in_array($request->get('streamId'),
                    $request->user()->streams()->get()->pluck('id')->toArray())) {
                return $this->redirectNonOwned($request);
            }
        }

        return $next($request);
    }

    private function redirectEmptyAssigns($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Current user has no assigned streams',
                'status'  => CurlService::STATUS_ERROR
            ], 404);
        }

        return redirect()->route('emptyAssigns');
    }

    private function redirectNonOwned($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Current user can\'t get access to selected stream',
                'status'  => CurlService::STATUS_ERROR
            ], 403);
        }

        return redirect()->route('emptyAssigns');
    }
}
