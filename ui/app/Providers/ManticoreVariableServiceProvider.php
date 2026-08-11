<?php

namespace App\Providers;

use App\Services\Curl\CurlService;
use App\Services\ManticoreService;
use App\Services\ManticoreVariableService;
use Illuminate\Support\ServiceProvider;

class ManticoreVariableServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(ManticoreVariableService::class, function ($app) {
            return new ManticoreVariableService($app->make(CurlService::class));
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
