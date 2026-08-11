<?php

namespace App\Providers;

use App\Services\TsvParser;
use App\Services\FileCacheService;
use Illuminate\Support\ServiceProvider;

class TsvParserServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(FileCacheService::class, function ($app) {
            return new FileCacheService();
        });

        $this->app->singleton(TsvParser::class, function ($app) {
            return new TsvParser($app->make(FileCacheService::class));
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
