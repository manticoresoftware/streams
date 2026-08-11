<?php

namespace App\Http\Middleware;

use App\Services\Curl\CurlService;
use App\Services\ManticoreService;
use Closure;

class ManticoreConnection {
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     *
     * @return mixed
     */
    public function handle( $request, Closure $next ) {
        /** @var ManticoreService $manticoreService */
        $manticoreService = \App::get( ManticoreService::class );

        if ( isset( $request['streamId'] ) ) {
            $manticoreService->setStream( (int) $request['streamId'] );
        }

        if ( $manticoreService->getError() ) {
            $message = $manticoreService->getError();
            if ( $request->isJson() ) {
                return response()->json( [ 'status' => CurlService::STATUS_ERROR, 'message' => $message ], 503 );
            }

            return response()->json( [ 'status' => CurlService::STATUS_ERROR, 'message' => $message ], 400 );
        }

        return $next( $request );
    }
}
