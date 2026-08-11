<?php

namespace App\Providers;



use App\Services\KafkaConnection;
use Illuminate\Support\ServiceProvider;

class KafkaConnectionProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(KafkaConnection::class, function ($app) {
            return new KafkaConnection();
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
