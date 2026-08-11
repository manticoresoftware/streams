<?php

namespace App\Providers;

use App\Services\Curl\CurlService;
use App\Services\ManticoreService;
use Illuminate\Support\ServiceProvider;

class ManticoreServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(ManticoreService::class, function ($app) {
            return new ManticoreService(\Auth::user()->process);
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
