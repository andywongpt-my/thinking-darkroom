<?php

use App\Http\Middleware\AgentOrUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Breeze auth routes — loaded INSIDE the web group so they get the
            // session/CSRF/verified middleware (the `then` closure runs outside
            // the web group by default, which breaks $request->session()).
            \Illuminate\Support\Facades\Route::middleware('web')->group(
                __DIR__.'/../routes/auth.php',
            );
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Vercel terminates TLS before forwarding requests to the PHP function.
        // Trust only its scheme signal; client-supplied forwarding IP/host data
        // must not influence login throttling, audit trails, or reset URLs.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_PROTO,
        );

        // Same-origin SPA + Inertia: let the frontend call /api/projects over
        // the authenticated session; standalone agents still use tokens.
        $middleware->statefulApi();

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // WebMCP agent tool endpoints: authenticated session user OR agent token.
        $middleware->alias([
            'webmcp.agent-or-user' => AgentOrUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
