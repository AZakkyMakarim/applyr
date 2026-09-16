<?php

namespace App\Providers;

use App\Adapters\AdapterRegistry;
use App\Adapters\Glints\GlintsAdapter;
use App\Adapters\JobStreet\JobStreetAdapter;
use App\Ai\AiProvider;
use App\Ai\GeminiProvider;
use App\Pdf\BrowsershotPdfRenderer;
use App\Pdf\PdfRenderer;
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

        $this->app->bind(AiProvider::class, GeminiProvider::class);
        $this->app->bind(PdfRenderer::class, BrowsershotPdfRenderer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
