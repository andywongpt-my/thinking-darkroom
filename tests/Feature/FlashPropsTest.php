<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Tests\TestCase;

/**
 * Flash propagation regression (2026-09-04 AGY LOW, verified empirically):
 * controller redirects flash PLAIN session keys via ->with('flash'|'vlm_remaining').
 * Inertia's own pipeline only reads its "inertia.flash_data" key, so without
 * HandleInertiaRequests::share() mapping those keys, NEITHER prop ever reached
 * page.props — the success banner and the upload auto-upgrade trigger were
 * dead code. These tests pin the propagation.
 */
class FlashPropsTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    private Project $project;

    private string $inertiaVersion = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic, network-free: no VLM key, all outbound HTTP faked.
        config([
            'services.vlm.key' => null,
            'services.agent_llm.key' => null,
        ]);
        Http::fake();

        $this->photographer = User::factory()->create();
        $this->project = Project::factory()->create(['owner_id' => $this->photographer->id]);
        $this->project->members()->syncWithoutDetaching([
            $this->photographer->id => ['role' => Domain::ROLE_OWNER],
        ]);

        // Inertia 409s a GET whose X-Inertia-Version header mismatches the
        // manifest hash — mirror the app's own version() resolver.
        $manifest = public_path('build/manifest.json');
        $this->inertiaVersion = is_file($manifest) ? hash_file('xxh128', $manifest) : '';
    }

    private function getInertia(): TestResponse
    {
        // Inertia's ResponseFactory keeps shared props in per-process static
        // state. In feature tests many requests share one process, so a
        // previous request's resolved 'flash'/'vlm_remaining' values leak
        // into later assertions. Production lambdas never see this (fresh
        // process per request); flush to model the real lifecycle.
        Inertia::flushShared();

        return $this->actingAs($this->photographer)
            ->get(route('workspace.show', $this->project), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $this->inertiaVersion,
            ]);
    }

    public function test_upload_flash_and_vlm_remaining_reach_inertia_props(): void
    {
        $this->actingAs($this->photographer)
            ->post(route('workspace.upload', $this->project), [
                'photos' => [UploadedFile::fake()->image('t.jpg', 32, 32)],
            ])
            ->assertRedirect();

        // The flashed session data must surface on the follow-up Inertia
        // request as page props (success banner + auto-upgrade trigger).
        $props = $this->getInertia()
            ->assertOk()
            ->json('props');

        $this->assertSame('Photos uploaded. Analysis started.', $props['flash']['success'] ?? null);
        $this->assertSame(0, $props['vlm_remaining'] ?? null);
    }

    public function test_flash_props_expire_after_being_shown_once(): void
    {
        $this->actingAs($this->photographer)
            ->post(route('workspace.upload', $this->project), [
                'photos' => [UploadedFile::fake()->image('t.jpg', 32, 32)],
            ])
            ->assertRedirect();

        $this->getInertia()->assertOk();

        // Session flash expires after the follow-up request: the next page
        // load must NOT re-show the old banner (one-shot semantics).
        $props = $this->getInertia()
            ->assertOk()
            ->json('props');

        $this->assertArrayNotHasKey('flash', $props);
        $this->assertArrayNotHasKey('vlm_remaining', $props);
    }
}
