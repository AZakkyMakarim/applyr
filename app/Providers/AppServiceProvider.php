<?php

namespace App\Providers;

use App\Adapters\AdapterRegistry;
use App\Adapters\Glints\GlintsAdapter;
use App\Adapters\JobStreet\JobStreetAdapter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->tag([GlintsAdapter::class, JobStreetAdapter::class], 'adapters');

        $this->app->singleton(AdapterRegistry::class, fn ($app) => new AdapterRegistry($app->tagged('adapters')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
