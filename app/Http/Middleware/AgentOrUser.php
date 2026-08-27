<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WebMCP agent-or-user guard.
 *
 * The web app calls the WebMCP API via the same authenticated session. A
 * standalone agent (competition harness) calls with a personal access token.
 * Either is acceptable to invoke a tool — but authority enforcement (PROPOSE
 * can't execute, agents can't approve) happens downstream in controllers.
 */
class AgentOrUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
