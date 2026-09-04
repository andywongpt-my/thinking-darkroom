<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            // Controller redirects flash plain session keys via ->with().
            // Inertia's own flash pipeline only reads its "inertia.flash_data"
            // session key, so without this mapping NEITHER prop ever reached
            // page.props (2026-09-04 AGY LOW verified empirically): the
            // success banner and the vlm_remaining auto-upgrade trigger were
            // dead code. Session flashes expire after the follow-up request;
            // only non-expired values are emitted, keeping the banner
            // one-shot. Inertia::flash() payloads (unused here) would
            // override these keys on merge — acceptable, as this app never
            // uses that API.
            ...array_filter([
                'flash' => $request->session()->get('flash'),
                'vlm_remaining' => $request->session()->get('vlm_remaining'),
            ], fn ($value): bool => $value !== null),
        ];
    }
}
