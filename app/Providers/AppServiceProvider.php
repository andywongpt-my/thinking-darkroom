<?php

namespace App\Providers;

use App\Services\Retouch\DemoRetouchRenderer;
use App\Services\Retouch\RetouchRenderer;
use App\Support\GdAvailability;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Sprint 4 — the retouch renderer binding. The demo ships the
        // deterministic GD DemoRetouchRenderer; a real engine later is a
        // one-line swap here.
        $this->app->singleton(RetouchRenderer::class, function ($app) {
            return new DemoRetouchRenderer($app->make(GdAvailability::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
