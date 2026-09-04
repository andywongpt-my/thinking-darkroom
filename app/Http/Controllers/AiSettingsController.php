<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;

/**
 * P2c — per-photographer BYO-key AI settings (Settings → AI provider).
 *
 * Contract:
 * - The API key is accepted once, encrypted (Laravel Crypt, APP_KEY-scoped)
 *   via User::setAiApiKey(), and NEVER returned to the client afterwards.
 * - `clear_key=on` wipes the stored key; provider/model/base_url stay as-is
 *   and analysis falls back to deployment env defaults (no key → GD).
 * - Validation: provider must be a preset name or empty (env defaults),
 *   key min length 20 (real sk-or-… keys are 60+ chars), model is a free
 *   string (the server allow-lists everything that leaves the model).
 * - Agent accounts get 403: they hold no photographer authority and never
 *   configure inference credentials.
 * - Everything is optional: clearing provider/model/base_url returns the
 *   account to deployment env defaults.
 */
class AiSettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->isAgent()) {
            abort(403, 'Agent accounts cannot configure AI settings.');
        }

        $validated = $request->validate([
            'ai_provider' => ['nullable', Rule::in(array_keys(User::AI_PROVIDER_PRESETS))],
            'ai_api_key' => ['nullable', 'string', 'min:20', 'max:4096'],
            'ai_model' => ['nullable', 'string', 'max:255'],
            'ai_base_url' => ['nullable', 'string', 'max:2048', 'url'],
            'clear_key' => ['nullable', 'boolean'],
        ]);

        $user->fill([
            'ai_provider' => $validated['ai_provider'] ?? null,
            'ai_model' => $this->normalizedModel($validated['ai_model'] ?? null),
            'ai_base_url' => $validated['ai_base_url'] ?? null,
        ]);

        if (($validated['clear_key'] ?? false) === true) {
            $user->setAiApiKey(null);
        } elseif (! empty($validated['ai_api_key'])) {
            $user->setAiApiKey(trim($validated['ai_api_key']));
        }

        $user->save();

        return Redirect::route('profile.edit')->with('status', 'ai-settings-saved');
    }

    /**
     * Store an explicit empty string as NULL so "user never set a model"
     * stays distinguishable from "user wants an empty model name".
     */
    private function normalizedModel(?string $model): ?string
    {
        $model = trim((string) $model);

        return $model === '' ? null : $model;
    }
}
