<?php

namespace Tests\Feature;

use App\Domain\Culling\PhotoObservation;
use App\Domain\Domain;
use App\Models\Photo;
use App\Models\PhotoObservationRecord;
use App\Models\PhotographerDecision;
use App\Models\Project;
use App\Models\User;
use App\Services\Culling\ContextAwareCullingService;
use App\Services\Culling\DemoPhotoAnalysisProvider;
use App\Services\Culling\PhotoAnalysisProvider;
use App\Services\ProposalService;
use App\Support\WebmcpToolCatalog;
use App\Support\GdAvailability;
use Database\Seeders\Sprint3CullingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint 3 — context-aware culling certification tests.
 *
 * Covers the full Sprint 3 gate list:
 *  - real JPEG technical analysis (dataset-driven, no fixtures)
 *  - provenance separation (pixel_analysis vs demo_sidecar_annotation)
 *  - perceptual-hash grouping of the duplicate pair
 *  - Creative Brief causal counterfactuals (A vs B on the SAME observation)
 *  - reverse counterfactual (posed frame, Brief A vs B)
 *  - no-brief safe fallback
 *  - propose_cull stays proposal-only with structured evidence
 *  - photographer override (human-only, persisted, agent/viewer denied)
 *  - human-authority catalogue boundary (no final-cull tools)
 *  - Sprint 1 + Sprint 2 regressions stay green
 */
class ContextAwareCullingTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $photographer;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate filesystem writes (e.g. the no-GD fallback sidecar probe)
        // from the real dev dataset under storage/app/public/.
        Storage::fake('public');

        // Deterministic certification dataset (12 original synthetic JPEGs).
        $this->seed(Sprint3CullingSeeder::class);

        $this->project = Project::where('name', Sprint3CullingSeeder::PROJECT_NAME)->firstOrFail();
        $this->photographer = User::where('email', 'photographer@webmcp.test')->firstOrFail();
        $this->agent = User::where('email', 'agent@webmcp.test')->firstOrFail();

        // Fresh pixel analysis for every test (stable evidence per run).
        PhotoObservationRecord::where('project_id', $this->project->id)->delete();
        DemoPhotoAnalysisProvider::resetSimilarityMemory();
        app(ContextAwareCullingService::class)->analyzeProject($this->project);
    }

    /* ------------------------------ dataset / analysis ------------------------------ */

    public function test_real_jpeg_analysis_produces_observations_for_every_dataset_photo(): void
    {
        $observations = app(ContextAwareCullingService::class)->observationsFor($this->project);

        $this->assertCount(12, $observations, 'all 12 dataset photos must be analyzed');

        foreach ($observations as $o) {
            $this->assertNotSame('unknown', $o->sharpness()['assessment'], 'a normal JPEG must never be entirely unknown');
            $this->assertNotSame('unknown', $o->exposure()['assessment']);
            $this->assertGreaterThan(0.0, $o->sharpness()['confidence']);
        }
    }

    public function test_technically_different_images_produce_distinguishable_observations(): void
    {
        $observations = app(ContextAwareCullingService::class)->observationsFor($this->project);

        $sharp = $this->observationForFile($observations, '01-candid-laugh-sharp.jpg');
        $soft = $this->observationForFile($observations, '02-soft-emotive-gaze.jpg');
        $under = $this->observationForFile($observations, '05-dusk-moody-underexposed.jpg');
        $clip = $this->observationForFile($observations, '06-noon-highlight-clipping.jpg');
        $blur = $this->observationForFile($observations, '03-runner-motion-blur.jpg');

        $this->assertSame('sharp', $sharp->sharpness()['assessment']);
        $this->assertSame('slightly_soft', $soft->sharpness()['assessment']);
        $this->assertSame('underexposed', $under->exposure()['assessment']);
        $this->assertSame('overexposed', $clip->exposure()['assessment']);
        $this->assertSame('risk', $clip->highlightClipping()['assessment']);
        $this->assertContains($blur->motionBlur()['assessment'], ['mild', 'strong'], 'the motion frame must register blur');
    }

    public function test_provenance_separates_pixel_analysis_from_sidecar_annotation(): void
    {
        $observations = app(ContextAwareCullingService::class)->observationsFor($this->project);
        $soft = $this->observationForFile($observations, '02-soft-emotive-gaze.jpg');

        // Provider-level provenance: deterministic pixel analysis.
        $this->assertSame(Domain::OBSERVATION_PROVIDER_DEMO, $soft->provider);
        $this->assertSame(
            Domain::OBSERVATION_PROVENANCES[Domain::OBSERVATION_PROVIDER_DEMO],
            $soft->provenance,
        );

        // API responses must label each section's source explicitly.
        $response = $this->actingAs($this->agent)
            ->getJson(route('api.webmcp.culling.photo-analysis', [$this->project->id, $soft->photoId]))
            ->assertOk();

        $response->assertJsonPath('observation.technical_provenance', 'pixel_analysis');
        $response->assertJsonPath('observation.creative_provenance', 'demo_sidecar_annotation');

        // The creative fields came from the sidecar, NOT from pixels: the
        // provider records no vision claims. eyes_open stays null (no face model).
        $this->assertNull($soft->eyesOpen());
    }

    public function test_missing_gd_reports_unknown_technical_and_honest_fallback_provenance(): void
    {
        $degradedProvider = new DemoPhotoAnalysisProvider(new GdAvailability(false));
        app()->instance(GdAvailability::class, new GdAvailability(false));
        app()->instance(PhotoAnalysisProvider::class, $degradedProvider);

        PhotoObservationRecord::where('project_id', $this->project->id)->delete();
        DemoPhotoAnalysisProvider::resetSimilarityMemory();

        $service = app(ContextAwareCullingService::class);
        $this->assertSame(12, $service->analyzeProject($this->project));

        $observations = $service->observationsFor($this->project);
        $observation = $this->observationForFile($observations, '02-soft-emotive-gaze.jpg');

        $this->assertSame(Domain::OBSERVATION_PROVENANCE_DEMO_GD_UNAVAILABLE, $observation->provenance);
        foreach (['sharpness', 'exposure', 'motion_blur', 'highlight_clipping'] as $dimension) {
            $this->assertSame('unknown', $observation->technical[$dimension]['assessment']);
            // JSON roundtrip normalizes 0.0 to int 0 (no JSON_PRESERVE_ZERO_FRACTION):
            // semantic requirement is exactly-zero confidence, not a PHP type.
            $this->assertEqualsWithDelta(0.0, $observation->technical[$dimension]['confidence'], 0.0001);
        }

        // similarityGroupFromPixels must also degrade without calling any GD
        // function, while preserving a human-authored sidecar group.
        $fallbackPath = "project-{$this->project->id}/no-gd.jpg";
        Storage::disk('public')->put($fallbackPath, 'not an image');
        Storage::disk('public')->put($fallbackPath.'.obs.json', json_encode([
            'similarity_group' => 'sidecar-fallback',
        ], JSON_THROW_ON_ERROR));
        $fallbackPhoto = (new Photo)->forceFill(['id' => 999, 'path' => $fallbackPath]);
        $fallback = (new DemoPhotoAnalysisProvider(new GdAvailability(false)))->observe($fallbackPhoto);
        $this->assertSame('sidecar-fallback', $fallback->similarityGroup);

        $this->actingAs($this->agent)
            ->getJson(route('api.webmcp.culling.photo-analysis', [$this->project->id, $observation->photoId]))
            ->assertOk()
            ->assertJsonPath('observation.provenance', Domain::OBSERVATION_PROVENANCE_DEMO_GD_UNAVAILABLE)
            ->assertJsonPath('observation.technical_provenance', Domain::OBSERVATION_PROVENANCE_DEMO_GD_UNAVAILABLE)
            ->assertJsonPath('observation.creative_provenance', 'demo_sidecar_annotation');
    }

    public function test_creative_fields_come_from_sidecar_and_missing_sidecar_is_honest(): void
    {
        // A photo whose stored bytes have NO sidecar file (upload path) must
        // report creative fields as unobserved — never invented.
        $bytes = file_get_contents(database_path('demo/culling-dataset/01-candid-laugh-sharp.jpg'));
        $path = "project-{$this->project->id}/upload-no-sidecar.jpg";
        Storage::disk('public')->put($path, $bytes);

        $photo = Photo::create([
            'project_id' => $this->project->id,
            'filename' => 'upload-no-sidecar.jpg',
            'original_name' => 'upload-no-sidecar.jpg',
            'path' => $path,
            'mime' => 'image/jpeg',
            'size_bytes' => strlen($bytes),
            'width' => 960,
            'height' => 640,
            'selection_state' => Domain::SELECTION_UNREVIEWED,
            'retouch_state' => Domain::RETOUCH_NONE,
        ]);

        $provider = app(DemoPhotoAnalysisProvider::class);
        DemoPhotoAnalysisProvider::resetSimilarityMemory();
        $o = $provider->observe($photo);

        $this->assertSame('unobserved', $o->creative['expression']);
        $this->assertSame('unobserved', $o->emotionStrength());
        $this->assertNotSame('unobserved', $o->sharpness()['assessment'], 'pixels are still analyzed');
    }

    public function test_phash_groups_intended_duplicate_pair_and_separates_others(): void
    {
        $observations = app(ContextAwareCullingService::class)->observationsFor($this->project);

        $burstA = $this->observationForFile($observations, '09-burst-frame-a.jpg');
        $burstB = $this->observationForFile($observations, '10-burst-frame-b.jpg');

        $this->assertNotNull($burstA->similarityGroup);
        $this->assertSame(
            $burstA->similarityGroup,
            $burstB->similarityGroup,
            'the burst pair must group together',
        );

        // Every other photo must NOT share that group (11 distinct groups).
        $groups = array_map(
            fn (PhotoObservation $o) => $o->similarityGroup,
            array_values($observations),
        );

        $this->assertSame(11, count(array_unique($groups)), '11 groups: the pair + 10 individuals');
    }

    /* ------------------------------ counterfactuals ------------------------------ */

    public function test_counterfactual_same_observation_brief_a_vs_b_changes_recommendation(): void
    {
        [$observation, $briefA, $briefB] = $this->softEmotiveCounterfactual();

        $service = app(ContextAwareCullingService::class);

        $recA = $service->recommend($observation, $briefA);
        $recB = $service->recommend($observation, $briefB);

        // Brief A (emotion > perfection): keep-family.
        $this->assertContains(
            $recA['recommendation'],
            [Domain::CULL_RECOMMEND_KEEP, Domain::CULL_RECOMMEND_STRONG_KEEP],
            'emotion-first brief should keep the soft-but-emotive frame',
        );

        // Brief B (precision > emotion): materially weaker.
        $this->assertContains(
            $recB['recommendation'],
            [Domain::CULL_RECOMMEND_REVIEW, Domain::CULL_RECOMMEND_REJECT_CANDIDATE],
            'precision-first brief must NOT keep the same frame',
        );

        // And the recommendation must be materially weaker, not just the score.
        $rank = [
            Domain::CULL_RECOMMEND_REJECT_CANDIDATE => 0,
            Domain::CULL_RECOMMEND_REVIEW => 1,
            Domain::CULL_RECOMMEND_KEEP => 2,
            Domain::CULL_RECOMMEND_STRONG_KEEP => 3,
        ];
        $this->assertGreaterThan($rank[$recB['recommendation']], $rank[$recA['recommendation']]);

        // influenced_by must name the brief paths that flipped the decision.
        $this->assertContains('selection_priority.emotion', $recA['influenced_by']);
        $this->assertContains('selection_priority.technical', $recB['influenced_by']);
    }

    public function test_reverse_counterfactual_posed_frame_brief_b_strengthens(): void
    {
        [$observation, $briefA, $briefB] = $this->posedCounterfactual();

        $service = app(ContextAwareCullingService::class);

        $recA = $service->recommend($observation, $briefA);
        $recB = $service->recommend($observation, $briefB);

        // Brief A (documentary intimacy, avoid posed): weaker for a posed frame.
        $this->assertContains(
            $recA['recommendation'],
            [Domain::CULL_RECOMMEND_REVIEW, Domain::CULL_RECOMMEND_REJECT_CANDIDATE],
            'documentary-intimacy brief should not embrace a heavily posed frame',
        );

        // Brief B (formal precision, posed acceptable): materially stronger.
        $this->assertContains(
            $recB['recommendation'],
            [Domain::CULL_RECOMMEND_KEEP, Domain::CULL_RECOMMEND_STRONG_KEEP],
            'formal-precision brief should keep the posed, sharp frame',
        );

        $rank = [
            Domain::CULL_RECOMMEND_REJECT_CANDIDATE => 0,
            Domain::CULL_RECOMMEND_REVIEW => 1,
            Domain::CULL_RECOMMEND_KEEP => 2,
            Domain::CULL_RECOMMEND_STRONG_KEEP => 3,
        ];
        $this->assertGreaterThan($rank[$recA['recommendation']], $rank[$recB['recommendation']]);
    }

    public function test_no_brief_fallback_is_review_with_low_confidence(): void
    {
        // Project 1 (Coastal Studio) has a plain brief, not an adopted
        // direction — no structured intent. Simulate by recommending against
        // a null intent.
        $observations = app(ContextAwareCullingService::class)->observationsFor($this->project);
        $soft = $this->observationForFile($observations, '02-soft-emotive-gaze.jpg');

        $rec = app(ContextAwareCullingService::class)->recommend($soft, null);

        $this->assertSame(Domain::CULL_RECOMMEND_REVIEW, $rec['recommendation']);
        $this->assertSame(0.3, $rec['confidence']);
        $this->assertStringContainsStringIgnoringCase('no adopted creative brief', $rec['tradeoff']);
        $this->assertSame([], $rec['influenced_by'], 'no invented brief paths');
    }

    public function test_influenced_by_contains_relevant_brief_paths_and_rationales_exist(): void
    {
        $service = app(ContextAwareCullingService::class);
        $intent = $this->adoptedIntent();

        $observations = app(ContextAwareCullingService::class)->observationsFor($this->project);

        foreach ($observations as $o) {
            $rec = $service->recommend($o, $intent);

            $this->assertNotSame('', $rec['technical_rationale'], 'technical rationale always present');
            $this->assertNotSame('', $rec['creative_rationale'], 'creative rationale always present');
            $this->assertNotSame('', $rec['tradeoff'], 'tradeoff rationale always present');

            if ($rec['recommendation'] !== Domain::CULL_RECOMMEND_REVIEW || $rec['influenced_by'] !== []) {
                $this->assertNotEmpty($rec['influenced_by']);
            }

            foreach ($rec['influenced_by'] as $path) {
                $this->assertMatchesRegularExpression(
                    '/^(selection_priority\.|avoid\.|mood\.|creative\.)/',
                    $path,
                    "influenced_by path [{$path}] must reference a Creative Brief dimension",
                );
            }
        }
    }

    /* ------------------------------ proposals stay proposals ------------------------------ */

    public function test_context_aware_propose_cull_creates_proposal_only_with_structured_items(): void
    {
        $photo = $this->photoByFile('02-soft-emotive-gaze.jpg');

        $response = $this->actingAs($this->agent)
            ->postJson(route('api.webmcp.proposals.cull', [$this->project->id]), [
                'summary' => 'Context-aware cull proposal (certification)',
                'items' => [[
                    'photo_id' => $photo->id,
                    'action' => 'cull',
                    'rationale' => 'agent-proposed after context-aware analysis',
                ]],
            ])
            ->assertCreated();

        $proposal = $response->json('proposal');
        $this->assertSame(Domain::STATE_PENDING_REVIEW, $proposal['status']);

        // Structured evidence present in the item params.
        $item = $proposal['items'][0];
        $this->assertTrue($item['params']['context_aware']);
        $this->assertContains($item['params']['recommendation'], Domain::CULL_RECOMMENDATIONS);
        $this->assertIsFloat($item['params']['confidence']);
        $this->assertNotEmpty($item['params']['technical_rationale']);
        $this->assertNotEmpty($item['params']['creative_rationale']);
        $this->assertNotEmpty($item['params']['tradeoff']);
        $this->assertIsArray($item['params']['influenced_by']);
        $this->assertSame('pixel_analysis', $item['params']['observation_provenance']['technical']);
        $this->assertSame('demo_sidecar_annotation', $item['params']['observation_provenance']['creative']);

        // PROPOSAL-ONLY: the photo's selection state is untouched.
        $this->assertSame(
            Domain::SELECTION_UNREVIEWED,
            $photo->fresh()->selection_state,
            'propose_cull must never mutate selection_state',
        );
    }

    public function test_technically_weak_creatively_strong_can_be_keep_while_mismatched_strong_frame_ranks_lower(): void
    {
        $service = app(ContextAwareCullingService::class);
        $intent = $this->adoptedIntent();
        $observations = app(ContextAwareCullingService::class)->observationsFor($this->project);

        $soft = $service->recommend($this->observationForFile($observations, '02-soft-emotive-gaze.jpg'), $intent);
        $flat = $service->recommend($this->observationForFile($observations, '08-flat-expression.jpg'), $intent);

        // Soft but emotionally strong → keep-family under the emotion-first brief.
        $this->assertContains(
            $soft['recommendation'],
            [Domain::CULL_RECOMMEND_KEEP, Domain::CULL_RECOMMEND_STRONG_KEEP],
        );

        // Technically sharp but creatively mismatched → must not outrank it.
        $rank = [
            Domain::CULL_RECOMMEND_REJECT_CANDIDATE => 0,
            Domain::CULL_RECOMMEND_REVIEW => 1,
            Domain::CULL_RECOMMEND_KEEP => 2,
            Domain::CULL_RECOMMEND_STRONG_KEEP => 3,
        ];
        $this->assertLessThan(
            $rank[$soft['recommendation']],
            $rank[$flat['recommendation']],
            'technically-strong + creatively-flat must rank below soft + emotive',
        );
    }

    /* ------------------------------ photographer override ------------------------------ */

    public function test_photographer_override_persists_and_changes_selection(): void
    {
        $photo = $this->photoByFile('03-runner-motion-blur.jpg'); // agent leans review/reject

        $response = $this->actingAs($this->photographer)
            ->postJson(route('culling.photographer-decide', [$this->project->id, $photo->id]), [
                'decision' => 'keep',
                'note' => 'The expression matters more than the softness.',
                'override' => true,
            ])
            ->assertCreated();

        $this->assertSame('keep', $response->json('decision.decision'));
        $this->assertTrue($response->json('decision.override'));
        $this->assertSame(Domain::SELECTION_SELECTED, $photo->fresh()->selection_state);

        // Persisted with rationale + photo reference.
        $this->assertDatabaseHas('photographer_decisions', [
            'photo_id' => $photo->id,
            'decision' => 'keep',
            'note' => 'The expression matters more than the softness.',
        ]);
    }

    public function test_project_role_agent_cannot_override(): void
    {
        $photo = $this->photoByFile('01-candid-laugh-sharp.jpg');

        // Even a project-role agent (is_agent account) is denied.
        $this->actingAs($this->agent)
            ->postJson(route('culling.photographer-decide', [$this->project->id, $photo->id]), [
                'decision' => 'reject',
            ])
            ->assertStatus(403);

        $this->assertSame(0, PhotographerDecision::where('photo_id', $photo->id)->count());
        $this->assertSame(Domain::SELECTION_UNREVIEWED, $photo->fresh()->selection_state);
    }

    public function test_account_level_agent_cannot_override_even_without_flag_check(): void
    {
        // Defense-in-depth: an account with is_agent=false but a project
        // AGENT role is still denied (two-layer boundary).
        $roleAgent = User::factory()->create(['is_agent' => false]);
        $this->project->members()->syncWithoutDetaching([
            $roleAgent->id => ['role' => Domain::ROLE_AGENT],
        ]);

        $photo = $this->photoByFile('01-candid-laugh-sharp.jpg');

        $this->actingAs($roleAgent)
            ->postJson(route('culling.photographer-decide', [$this->project->id, $photo->id]), [
                'decision' => 'reject',
            ])
            ->assertStatus(403);

        $this->assertSame(0, PhotographerDecision::where('photo_id', $photo->id)->count());
    }

    public function test_viewer_cannot_override(): void
    {
        $viewer = User::factory()->create(['is_agent' => false]);
        $this->project->members()->syncWithoutDetaching([
            $viewer->id => ['role' => Domain::ROLE_VIEWER],
        ]);

        $photo = $this->photoByFile('01-candid-laugh-sharp.jpg');

        $this->actingAs($viewer)
            ->postJson(route('culling.photographer-decide', [$this->project->id, $photo->id]), [
                'decision' => 'keep',
            ])
            ->assertStatus(403);

        $this->assertSame(0, PhotographerDecision::where('photo_id', $photo->id)->count());
    }

    /* ------------------------------ human authority boundary ------------------------------ */

    public function test_forbidden_final_cull_tools_are_absent_from_catalogue(): void
    {
        $names = array_keys(WebmcpToolCatalog::all());

        foreach ([
            'finalize_cull', 'approve_own_cull', 'force_selection',
            'delete_rejected_photos', 'delete_original', 'final_delivery',
            // The photographer decision endpoint is HUMAN authority — never a tool.
            'photographer_culling_decide',
        ] as $banned) {
            $this->assertNotContains($banned, $names, "final-authority tool [{$banned}] must never exist");
        }

        foreach (Domain::FORBIDDEN_TOOL_ACTIONS as $action) {
            $this->assertNotContains($action, $names);
        }
    }

    public function test_analyze_project_photos_is_analyze_authority_not_read_only(): void
    {
        $catalog = WebmcpToolCatalog::all();

        $this->assertArrayHasKey('analyze_project_photos', $catalog);
        $this->assertSame(Domain::AUTHORITY_ANALYZE, $catalog['analyze_project_photos']['authority']);
        $this->assertFalse($catalog['analyze_project_photos']['read_only']);
        $this->assertSame('POST', $catalog['analyze_project_photos']['method']);

        // The two genuinely read-only culling tools stay READ.
        $this->assertSame(Domain::AUTHORITY_READ, $catalog['get_photo_analysis']['authority']);
        $this->assertTrue($catalog['get_photo_analysis']['read_only']);
        $this->assertSame(Domain::AUTHORITY_READ, $catalog['get_culling_context']['authority']);
        $this->assertTrue($catalog['get_culling_context']['read_only']);

        // Sprint 3 static inventory: exactly 3 culling tools.
        $culling = array_filter(
            $catalog,
            fn (array $t) => in_array($t['name'], ['get_photo_analysis', 'get_culling_context', 'analyze_project_photos'], true),
        );
        $this->assertCount(3, $culling);

        // Certified totals: 19 static tools, 20 only with the dynamic EXECUTE.
        $static = array_filter($catalog, fn (array $t) => ! $t['dynamic']);
        $this->assertCount(19, $static);
        $this->assertSame(1, count(array_filter($catalog, fn (array $t) => $t['dynamic'])));

        // Every tool authority stays inside the agent vocabulary (HUMAN absent).
        foreach ($catalog as $tool) {
            $this->assertContains($tool['authority'], Domain::AGENT_AUTHORITIES);
        }
    }

    public function test_analyze_endpoint_audit_logs_analyze_authority(): void
    {
        $this->actingAs($this->agent)
            ->postJson(route('api.webmcp.culling.analyze', [$this->project->id]))
            ->assertOk();

        $this->assertDatabaseHas('agent_tool_calls', [
            'tool_name' => 'analyze_project_photos',
            'authority' => Domain::AUTHORITY_ANALYZE,
            'result_status' => Domain::RESULT_COMPLETED,
        ]);
    }

    public function test_project_isolation_across_culling_endpoints(): void
    {
        // A second project's photo must not be readable through this one.
        $other = Project::create([
            'name' => 'Isolation Probe — Sprint 3',
            'description' => 'probe',
            'status' => 'active',
            'owner_id' => $this->photographer->id,
        ]);
        $other->members()->syncWithoutDetaching([
            $this->photographer->id => ['role' => Domain::ROLE_OWNER],
            $this->agent->id => ['role' => Domain::ROLE_AGENT],
        ]);
        $otherPhoto = Photo::create([
            'project_id' => $other->id,
            'filename' => 'other.jpg',
            'original_name' => 'other.jpg',
            'path' => null,
            'mime' => 'image/jpeg',
            'size_bytes' => 1,
            'width' => 10,
            'height' => 10,
            'selection_state' => Domain::SELECTION_UNREVIEWED,
            'retouch_state' => Domain::RETOUCH_NONE,
        ]);

        $this->actingAs($this->agent)
            ->getJson(route('api.webmcp.culling.photo-analysis', [$this->project->id, $otherPhoto->id]))
            ->assertStatus(404);

        // And proposing it through this project's endpoint is rejected.
        $this->actingAs($this->agent)
            ->postJson(route('api.webmcp.proposals.cull', [$this->project->id]), [
                'items' => [['photo_id' => $otherPhoto->id, 'action' => 'cull']],
            ])
            ->assertStatus(422);

        $this->assertSame(0, $this->project->proposals()->count());
    }

    public function test_culling_read_tools_are_audit_logged(): void
    {
        $photo = $this->photoByFile('01-candid-laugh-sharp.jpg');

        $this->actingAs($this->agent)
            ->getJson(route('api.webmcp.culling.photo-analysis', [$this->project->id, $photo->id]))
            ->assertOk();

        $this->assertDatabaseHas('agent_tool_calls', [
            'tool_name' => 'get_photo_analysis',
            'authority' => Domain::AUTHORITY_READ,
            'result_status' => Domain::RESULT_COMPLETED,
        ]);

        $this->actingAs($this->agent)
            ->getJson(route('api.webmcp.culling.context', [$this->project->id]))
            ->assertOk();

        $this->assertDatabaseHas('agent_tool_calls', [
            'tool_name' => 'get_culling_context',
            'authority' => Domain::AUTHORITY_READ,
        ]);
    }

    public function test_analysis_never_deletes_originals(): void
    {
        $before = Photo::count();

        $this->actingAs($this->agent)
            ->postJson(route('api.webmcp.culling.analyze', [$this->project->id]))
            ->assertOk();

        $this->assertSame($before, Photo::count(), 'analysis must never delete photos');
        $this->assertSame(12, PhotoObservationRecord::where('project_id', $this->project->id)->count());
    }

    /* ------------------------------ Sprint 1/2 regressions ------------------------------ */

    public function test_sprint_1_authority_and_lifecycle_regressions_hold(): void
    {
        // Sprint 1: propose → approve → execute lifecycle still works, and
        // selections only change through approved execution.
        $photo = $this->photoByFile('11-neutral-control.jpg');

        $proposal = app(ProposalService::class)->createProposal(
            $this->project,
            $this->agent,
            Domain::TYPE_CULL,
            [[
                'photo_id' => $photo->id,
                'action' => 'cull',
                'kind' => 'selection',
                'rationale' => 'regression probe',
            ]],
            'Sprint 1 regression probe',
            ['created_via' => 'webmcp', 'tool' => 'propose_cull'],
        );

        $this->assertSame(Domain::STATE_PENDING_REVIEW, $proposal->status);
        $this->assertSame(Domain::SELECTION_UNREVIEWED, $photo->fresh()->selection_state);

        // Agent cannot approve (Sprint 1 boundary).
        $this->actingAs($this->agent)
            ->postJson(route('proposals.approve', [$this->project->id, $proposal->id]))
            ->assertStatus(403);

        // Photographer approves and executes (Sprint 1 lifecycle).
        $this->actingAs($this->photographer)
            ->postJson(route('proposals.approve', [$this->project->id, $proposal->id]))
            ->assertOk();

        $this->actingAs($this->photographer)
            ->postJson(route('api.webmcp.proposals.execute', [$this->project->id, $proposal->id]))
            ->assertOk();

        $this->assertSame(Domain::SELECTION_CULLED, $photo->fresh()->selection_state);
        $this->assertSame(Domain::STATE_EXECUTED, $proposal->fresh()->status);
    }

    public function test_sprint_2_creative_room_regressions_hold(): void
    {
        // Sprint 2: concept proposal → adopt flow is intact, and this
        // project's adopted direction is exactly the one the seeder set.
        $this->assertSame(1, $this->project->creativeConcepts()->where('status', Domain::CONCEPT_STATUS_ADOPTED)->count());

        // Agent proposing concepts still cannot self-adopt (Sprint 2 gate).
        $this->actingAs($this->agent)
            ->postJson(route('api.webmcp.creative.concepts', [$this->project->id]), [
                'concepts' => [[
                    'title' => 'Probe Concept Sprint 3',
                    'summary' => 'probe',
                    'content' => ['mood' => ['neutral']],
                ]],
            ])
            ->assertCreated();

        $this->assertSame(
            1,
            $this->project->creativeConcepts()->where('status', Domain::CONCEPT_STATUS_ADOPTED)->count(),
            'proposal must not change adoption state',
        );
    }

    /* ------------------------------ helpers ------------------------------ */

    /**
     * THE counterfactual input pair: the SAME PhotoObservation object,
     * two briefs that differ ONLY in selection priority / avoid list.
     *
     * @return array{0: PhotoObservation, 1: array, 2: array}
     */
    private function softEmotiveCounterfactual(): array
    {
        $observations = app(ContextAwareCullingService::class)->observationsFor($this->project);
        $observation = $this->observationForFile($observations, '02-soft-emotive-gaze.jpg');

        $briefA = [
            'mood' => ['intimate', 'documentary'],
            'story' => 'Emotional documentary intimacy; the feeling of the moment beats perfection.',
            'selection_priorities' => ['emotion' => 'primary', 'technical' => 'secondary'],
            'avoid' => ['overly posed expressions', 'stiff formal staging'],
        ];

        $briefB = [
            'mood' => ['intimate', 'documentary'],
            'story' => 'Technical precision first; emotional spontaneity second.',
            'selection_priorities' => ['technical' => 'primary', 'emotion' => 'secondary'],
            'avoid' => ['soft focus', 'motion blur', 'out-of-focus frames'],
        ];

        return [$observation, $briefA, $briefB];
    }

    /**
     * Reverse counterfactual: same sharp-but-heavily-posed observation,
     * documentary brief (A) vs formal-precision brief (B).
     *
     * @return array{0: PhotoObservation, 1: array, 2: array}
     */
    private function posedCounterfactual(): array
    {
        $observations = app(ContextAwareCullingService::class)->observationsFor($this->project);
        $observation = $this->observationForFile($observations, '04-posed-studio-portrait.jpg');

        $briefA = [
            'mood' => ['intimate', 'documentary'],
            'story' => 'Documentary candour — real moments, not staging.',
            'selection_priorities' => ['emotion' => 'primary', 'technical' => 'secondary'],
            'avoid' => ['overly posed expressions', 'stiff formal staging'],
        ];

        $briefB = [
            'mood' => ['formal', 'stiff'],
            'story' => 'Formal portrait precision; posed composition is acceptable and expected.',
            'selection_priorities' => ['technical' => 'primary', 'emotion' => 'secondary'],
            'avoid' => ['candid chaos', 'unplanned framing'],
        ];

        return [$observation, $briefA, $briefB];
    }

    /** @return array<string, mixed> the seeder's adopted structured intent */
    private function adoptedIntent(): array
    {
        $direction = app(\App\Services\CreativeRoomService::class)->structuredIntentFor($this->project);
        $this->assertNotNull($direction, 'the certification project must have an adopted direction');

        return $direction['intent'];
    }

    /** @param array<int, PhotoObservation> $observations */
    private function observationForFile(array $observations, string $file): PhotoObservation
    {
        $photo = $this->photoByFile($file);
        $this->assertArrayHasKey($photo->id, $observations, "missing observation for {$file}");

        return $observations[$photo->id];
    }

    private function photoByFile(string $file): Photo
    {
        $photo = $this->project->photos()->where('original_name', $file)->firstOrFail();

        return $photo;
    }
}
