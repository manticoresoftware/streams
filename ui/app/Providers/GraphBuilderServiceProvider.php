<?php

namespace App\Providers;


use App\Services\ColumnarService;
use App\Services\GraphBuilderService;
use Illuminate\Support\ServiceProvider;

class GraphBuilderServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(GraphBuilderService::class, function ($app) {
            return new GraphBuilderService(\Auth::user()->process, $app->make(ColumnarService::class));
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
