<?php

namespace App\Providers;

use App\Services\Culling\DemoPhotoAnalysisProvider;
use App\Services\Culling\PhotoAnalysisProvider;
use App\Services\Culling\VlmPhotoAnalysisProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Sprint 3 — the photo-analysis provider binding.
 *
 * Production runs the VLM provider (structured vision observations with an
 * honest external_vision_model provenance); with no VLM key configured it
 * self-disables and delegates to the deterministic demo pixel analyzer, so
 * nothing else in the codebase needs to know which provider ran —
 * observations always carry honest provenance from the provider itself.
 */
class PhotoAnalysisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PhotoAnalysisProvider::class, function ($app) {
            // Production: VLM observations with a deterministic GD fallback.
            // No VLM key configured → the VLM provider self-disables and
            // delegates every observe() to the demo pixel analyzer.
            return new VlmPhotoAnalysisProvider($app->make(DemoPhotoAnalysisProvider::class));
        });
    }
}
