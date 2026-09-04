<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'ai_provider', 'ai_model', 'ai_base_url'])]
#[Hidden(['password', 'remember_token', 'is_agent', 'ai_api_key_encrypted'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * P2c — provider presets for per-photographer BYO-key AI settings.
     * Each maps a friendly provider name to its OpenAI-compatible base URL.
     *
     * @var array<string, string>
     */
    public const AI_PROVIDER_PRESETS = [
        'openrouter' => 'https://openrouter.ai/api/v1',
        'nvidia_nim' => 'https://integrate.api.nvidia.com/v1',
    ];

    /**
     * Recommended default models per provider (editable by the user).
     *
     * @var array<string, string>
     */
    public const AI_PROVIDER_DEFAULT_MODELS = [
        'openrouter' => 'google/gemini-2.5-flash',
        'nvidia_nim' => 'meta/llama-3.2-90b-vision-instruct',
    ];

    /**
     * Store the user's API key encrypted (Laravel Crypt — APP_KEY scoped).
     * Never plaintext, never in logs, never in API responses.
     */
    public function setAiApiKey(?string $key): void
    {
        $this->forceFill([
            'ai_api_key_encrypted' => ($key !== null && $key !== '')
                ? encrypt($key)
                : null,
        ])->save();
    }

    /** The decrypted key (null when unset or undecryptable). */
    public function aiApiKey(): ?string
    {
        $stored = $this->ai_api_key_encrypted;

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $decrypted = decrypt($stored);

            return is_string($decrypted) && $decrypted !== '' ? $decrypted : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Effective OpenAI-compatible settings for THIS user: user overrides
     * win over deployment env defaults. Base URL falls back preset → env →
     * OpenRouter. Model falls back user → provider default.
     *
     * @return array{key: string, model: string, base_url: string}
     */
    public function effectiveAiSettings(): array
    {
        $provider = in_array($this->ai_provider, array_keys(self::AI_PROVIDER_PRESETS), true)
            ? (string) $this->ai_provider
            : 'openrouter';

        $key = $this->aiApiKey()
            ?? (string) (config('services.vlm.key') ?: '');

        $model = (string) ($this->ai_model ?: '')
            ?: (string) (config('services.vlm.model') ?: self::AI_PROVIDER_DEFAULT_MODELS[$provider]);

        // Base URL precedence: explicit user override → provider preset →
        // deployment env (self-hosted gateways) → OpenRouter preset. The env
        // fallback keeps deployments that front a private OpenAI-compatible
        // gateway working when a photographer brings only a key.
        $baseUrl = (string) ($this->ai_base_url ?: '')
            ?: ($provider !== 'openrouter' || $this->ai_provider !== null
                ? self::AI_PROVIDER_PRESETS[$provider]
                : (string) (config('services.vlm.base_url') ?: self::AI_PROVIDER_PRESETS[$provider]));

        return ['key' => $key, 'model' => $model, 'base_url' => $baseUrl];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->withTimestamps()
            ->withPivot('role');
    }

    public function agentPresences(): HasMany
    {
        return $this->hasMany(AgentPresence::class);
    }

    public function agentConversationMessages(): HasMany
    {
        return $this->hasMany(AgentConversationMessage::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(PhotographerDecision::class, 'photographer_id');
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class, 'agent_id');
    }

    /**
     * Machine actor boundary: agent accounts can only ever PROPOSE, never
     * exercise photographer authority.
     */
    public function isAgent(): bool
    {
        return (bool) ($this->is_agent ?? false);
    }
}
