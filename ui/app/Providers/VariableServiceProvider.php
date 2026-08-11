<?php

namespace App\Providers;

use App\Services\VariablesService;
use Illuminate\Support\ServiceProvider;

class VariableServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(VariablesService::class, function ($app) {
            return new VariablesService();
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
