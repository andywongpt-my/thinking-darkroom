<?php

namespace App\Providers;

use App\Services\Culling\DemoPhotoAnalysisProvider;
use App\Services\Culling\PhotoAnalysisProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Sprint 3 — the photo-analysis provider binding.
 *
 * The demo ships the deterministic DemoPhotoAnalysisProvider. Swapping in a
 * real VLM provider later is a one-line change here — nothing else in the
 * codebase may know which provider ran (observations always carry honest
 * provenance from the provider itself).
 */
class PhotoAnalysisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PhotoAnalysisProvider::class, function ($app) {
            return new DemoPhotoAnalysisProvider($app->make(\App\Support\GdAvailability::class));
        });
    }
}
