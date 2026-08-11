<?php

namespace App\Http\Middleware;

use App\Models\Streams;
use App\Services\ManticoreService;
use Closure;

class StreamSelectorMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $setViaPath = false;
        if (str_contains($request->path(), 'setStream')){
            $setViaPath = true;
        }

        if ($setViaPath || $request->has('streamId')) {

            if ($setViaPath){
                $pathChunks = explode('/', $request->path());
                $streamId = (int)array_pop($pathChunks);
            }else{
                $streamId = (int)$request->get('streamId');
            }

            if ( ! empty($request->user()) && (int)$request->user()->process !== $streamId) {
                if ( ! $request->user()->streams()->get()->isEmpty()) {
                    $processes = $request->user()->streams()->get();

                    if ($processes->contains(function ($value) use ($streamId) {
                        return $value->id === $streamId;
                    })) {
                        $request->user()->process = $streamId;
                        $request->user()->save();

                        /** @var ManticoreService $manticoreService */
                        $manticoreService = app(ManticoreService::class);
                        $manticoreService->setStream($streamId);
                    }
                }
            }
        }

        return $next($request);
    }
}
