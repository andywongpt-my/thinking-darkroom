<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2c — per-photographer BYO-key AI settings.
 *
 * Security contract under test:
 * - the key is stored ENCRYPTED (never plaintext in the DB column);
 * - the key NEVER round-trips to the client (page props / responses carry
 *   only a boolean has_key);
 * - agents are rejected (403) — they hold no photographer authority;
 * - invalid providers are rejected by validation;
 * - clear_key wipes the stored key; empty submissions reset to env defaults.
 */
class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_settings_page_renders_with_provider_options(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Profile/Edit')
            ->has('aiSettings')
            ->where('aiSettings.has_key', false)
            ->where('aiSettings.ai_provider', null)
            ->has('aiSettings.providers.openrouter')
            ->has('aiSettings.providers.nvidia_nim')
            ->has('aiSettings.default_models.openrouter')
        );
    }

    public function test_saved_key_is_encrypted_at_rest_and_never_echoed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile/ai-settings', [
                'ai_provider' => 'openrouter',
                'ai_api_key' => 'sk-or-v1-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'ai_model' => 'google/gemini-2.5-flash',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile')
            ->assertSessionHas('status', 'ai-settings-saved');

        $user->refresh();

        $this->assertSame('openrouter', $user->ai_provider);
        $this->assertSame('google/gemini-2.5-flash', $user->ai_model);

        // Encrypted at rest: the raw column is NOT the plaintext key…
        $raw = $user->getRawOriginal('ai_api_key_encrypted');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('sk-or-v1-aaaa', $raw);

        // …but decrypts (the model's own helper, serialize-aware) to exactly
        // what was submitted.
        $this->assertSame(
            'sk-or-v1-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            decrypt($raw),
        );
        $this->assertSame(
            'sk-or-v1-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            $user->aiApiKey(),
        );

        // The profile page only ever exposes the boolean, never the value.
        $page = $this->actingAs($user)->get('/profile');
        $page->assertOk();
        $page->assertInertia(fn ($inertia) => $inertia
            ->where('aiSettings.has_key', true)
            ->where('aiSettings.ai_provider', 'openrouter')
            ->where('aiSettings.ai_model', 'google/gemini-2.5-flash')
        );
        $this->assertStringNotContainsString('sk-or-v1-aaaa', $page->getContent());
    }

    public function test_provider_preset_fills_base_url_and_custom_url_is_kept(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile/ai-settings', [
                'ai_provider' => 'nvidia_nim',
            ])
            ->assertSessionHasNoErrors();

        // Preset base URL comes from User::effectiveAiSettings(), not storage.
        $this->assertNull($user->refresh()->ai_base_url);
        $this->assertSame(
            'https://integrate.api.nvidia.com/v1',
            $user->effectiveAiSettings()['base_url'],
        );

        $this->actingAs($user)
            ->patch('/profile/ai-settings', [
                'ai_base_url' => 'https://my-gateway.example.com/v1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('https://my-gateway.example.com/v1', $user->refresh()->ai_base_url);
    }

    public function test_invalid_provider_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile/ai-settings', [
                'ai_provider' => 'not-a-provider',
            ])
            ->assertSessionHasErrors('ai_provider');

        $this->assertNull($user->refresh()->ai_provider);
    }

    public function test_short_key_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile/ai-settings', [
                'ai_api_key' => 'short',
            ])
            ->assertSessionHasErrors('ai_api_key');

        $this->assertNull($user->refresh()->aiApiKey());
    }

    public function test_clear_key_wipes_stored_key_but_keeps_other_settings(): void
    {
        $user = User::factory()->create([
            'ai_provider' => 'openrouter',
            'ai_model' => 'google/gemini-2.5-flash',
        ]);
        $user->setAiApiKey('sk-or-v1-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        $this->assertNotNull($user->aiApiKey());

        // The PATCH represents the full desired state (the form always
        // submits every field), so provider/model ride along with clear_key.
        $this->actingAs($user)
            ->patch('/profile/ai-settings', [
                'ai_provider' => 'openrouter',
                'ai_model' => 'google/gemini-2.5-flash',
                'clear_key' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertNull($user->aiApiKey());
        $this->assertNull($user->getRawOriginal('ai_api_key_encrypted'));
        $this->assertSame('openrouter', $user->ai_provider);
        $this->assertSame('google/gemini-2.5-flash', $user->ai_model);
    }

    public function test_empty_submission_resets_provider_fields_to_env_defaults(): void
    {
        $user = User::factory()->create([
            'ai_provider' => 'nvidia_nim',
            'ai_model' => 'meta/llama-3.2-90b-vision-instruct',
            'ai_base_url' => 'https://my-gateway.example.com/v1',
        ]);

        $this->actingAs($user)
            ->patch('/profile/ai-settings', [])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertNull($user->ai_provider);
        $this->assertNull($user->ai_model);
        $this->assertNull($user->ai_base_url);
        // Falls back to the openrouter preset via effectiveAiSettings().
        $this->assertSame('https://openrouter.ai/api/v1', $user->effectiveAiSettings()['base_url']);
    }

    public function test_base_url_falls_back_to_env_gateway_when_no_provider_chosen(): void
    {
        // Deployment fronts a private OpenAI-compatible gateway; a photographer
        // brings only a key (no provider, no base URL override). The request
        // must hit the deployment's gateway, not the OpenRouter preset.
        config(['services.vlm.base_url' => 'https://gateway.internal/v1']);

        $user = User::factory()->create();
        $user->setAiApiKey('sk-or-v1-gatewaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        $this->assertSame('https://gateway.internal/v1', $user->effectiveAiSettings()['base_url']);

        // Choosing a preset restores preset precedence over env.
        $user->fill(['ai_provider' => 'nvidia_nim'])->save();
        $this->assertSame('https://integrate.api.nvidia.com/v1', $user->effectiveAiSettings()['base_url']);
    }

    public function test_agent_accounts_cannot_configure_ai_settings(): void
    {
        $agent = User::factory()->create(['is_agent' => true]);

        $this->actingAs($agent)
            ->patch('/profile/ai-settings', [
                'ai_provider' => 'openrouter',
                'ai_api_key' => 'sk-or-v1-cccccccccccccccccccccccccccccccccccccccc',
            ])
            ->assertForbidden();

        $agent->refresh();
        $this->assertNull($agent->aiApiKey());
        $this->assertNull($agent->ai_provider);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->patch('/profile/ai-settings', ['ai_provider' => 'openrouter'])
            ->assertRedirect('/login');
    }
}
