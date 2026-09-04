<?php

namespace App\Providers;

use App\Services\Retouch\DemoRetouchRenderer;
use App\Services\Retouch\ProRetouchRenderer;
use App\Services\Retouch\RetouchRenderer;
use App\Support\GdAvailability;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Sprint 4 — the retouch renderer binding. Production runs the
        // upgraded ProRetouchRenderer (same six-key contract, better
        // algorithms); the deterministic DemoRetouchRenderer stays one line
        // away for A/B and fixture parity.
        $this->app->singleton(RetouchRenderer::class, function ($app) {
            return new ProRetouchRenderer($app->make(GdAvailability::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            // A leaked public/hot marker would otherwise make @vite emit localhost URLs.
            Vite::useHotFile(storage_path('framework/vite.hot'));
        }

        Vite::prefetch(concurrency: 3);

        RateLimiter::for('project-create', fn (Request $request) => Limit::perMinute(6)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Project analysis can trigger image processing and write durable audit
        // records. Scope each limiter by authenticated actor + project, so one
        // busy project cannot exhaust another project's collaboration budget.
        RateLimiter::for('webmcp-analysis', fn (Request $request) => Limit::perMinute(6)
            ->by(self::actorProjectThrottleKey($request, 'analysis')));
        RateLimiter::for('workspace-upload', fn (Request $request) => Limit::perMinute(12)
            ->by(self::actorProjectThrottleKey($request, 'upload')));
        RateLimiter::for('webmcp-propose', fn (Request $request) => Limit::perMinute(20)
            ->by(self::actorProjectThrottleKey($request, 'propose')));
        RateLimiter::for('webmcp-presence', fn (Request $request) => Limit::perMinute(30)
            ->by(self::actorProjectThrottleKey($request, 'presence')));
    }

    private static function actorProjectThrottleKey(Request $request, string $scope): string
    {
        $project = $request->route('project');
        $projectId = is_object($project) && method_exists($project, 'getKey')
            ? (string) $project->getKey()
            : (string) $project;
        $actorId = $request->user()?->getAuthIdentifier() ?? $request->ip();

        return "{$scope}:{$actorId}:{$projectId}";
    }
}
