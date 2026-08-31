<?php

use App\Http\Middleware\AgentOrUser;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
            Route::middleware('web')->group(
                __DIR__.'/../routes/auth.php',
            );
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Vercel terminates TLS before forwarding requests to the PHP function.
        // Vercel's edge OVERWRITES X-Forwarded-For from the TCP connection
        // (anti-spoofing, vercel.com/docs/headers/request-headers), so trusting
        // FOR here resolves the real client IP inside the lambda. Without it
        // every request resolves to REMOTE_ADDR=127.0.0.1 and IP-keyed
        // throttles (register, login) collapse into ONE global bucket —
        // 5 registrations worldwide per minute would lock everyone out.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Same-origin SPA + Inertia: let the frontend call /api/projects over
        // the authenticated session; standalone agents still use tokens.
        $middleware->statefulApi();

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
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
