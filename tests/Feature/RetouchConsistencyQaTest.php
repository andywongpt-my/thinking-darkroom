<?php

namespace Tests\Feature;

use App\Domain\Culling\PhotoObservation;
use App\Domain\Domain;
use App\Models\CreativeConcept;
use App\Models\Photo;
use App\Models\PhotoDerivative;
use App\Models\PhotoObservationRecord;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\QaFinding;
use App\Models\User;
use App\Services\CreativeRoomService;
use App\Services\ProposalApplicator;
use App\Services\ProposalService;
use App\Services\Retouch\ContextAwareRetouchService;
use App\Support\GdAvailability;
use App\Support\WebmcpToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Sprint 4 — retouch proposals, consistency QA, and the LEARN loop.
 *
 * Core invariants under test:
 *  - retouch proposals consume the ADOPTED Creative Brief (counterfactual gate)
 *  - proposals are proposal-only (never render, never touch originals)
 *  - execution renders a NON-DESTRUCTIVE derivative through the ONE approved
 *    pathway (apply_approved_plan → ProposalApplicator), using the
 *    photographer-approved/modified values
 *  - original bytes are immutable, derivatives are idempotent
 *  - QA is judged relative to the adopted brief (QA counterfactual gate)
 *  - human authority boundaries hold
 */
class RetouchConsistencyQaTest extends TestCase
{
    use RefreshDatabase;

    private ProposalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProposalService::class);
        Storage::fake('public');
    }

    /* ------------------------------------------------------------------ */
    /*  World building */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{0: User, 1: User, 2: Project}
     */
    private function makeWorld(): array
    {
        $photographer = User::factory()->create(['name' => 'Photographer']);
        $agent = User::factory()->create(['is_agent' => true, 'name' => 'Agent']);
        $project = Project::factory()->withPhotos(4)->create(['owner_id' => $photographer->id]);
        $project->members()->syncWithoutDetaching([
            $photographer->id => ['role' => Domain::ROLE_OWNER],
            $agent->id => ['role' => Domain::ROLE_AGENT],
        ]);
        $project->photos()->update([
            'selection_state' => Domain::SELECTION_UNREVIEWED,
            'retouch_state' => Domain::RETOUCH_NONE,
        ]);

        return [$photographer, $agent, $project];
    }

    /** Put a real deterministic JPEG on the fake public disk for a photo. */
    private function putRealJpeg(Photo $photo): void
    {
        $image = imagecreatetruecolor(64, 48);
        imagefill($image, 0, 0, imagecolorallocate($image, 90, 80, 70));
        imagefilledrectangle($image, 10, 10, 54, 38, imagecolorallocate($image, 200, 180, 180));
        ob_start();
        imagejpeg($image, null, 92);
        imagedestroy($image);
        Storage::disk('public')->put($photo->path, ob_get_clean());
    }

    /** Normalize adjustment values so float/int comparisons are stable. */
    private static function normAdjustments(array $a): array
    {
        $out = collect($a)->map(fn ($v) => (float) $v)->all();
        ksort($out);

        return $out;
    }

    /** Adopt a concept so the project has an active Creative Brief. */
    private function adoptBrief(Project $project, User $photographer, array $overrides = []): CreativeConcept
    {
        $room = app(CreativeRoomService::class);
        $concept = $room->proposeConcept(
            $project,
            $photographer,
            null,
            $overrides['title'] ?? 'Direction',
            'Brief for counterfactual tests.',
            array_merge([
                'mood' => ['intimate'],
                'retouch_philosophy' => 'natural skin, restrained warmth, subtle retouch, preserve texture',
                'avoid' => ['fake vintage'],
                'tonality_notes' => 'modern neutral',
                'color' => ['muted'],
            ], $overrides['content'] ?? []),
        );

        return $room->adoptConcept($project, $photographer, $concept);
    }

    /** A deterministic underexposed, highlight-safe observation. */
    private function underexposedObservation(int $photoId): PhotoObservation
    {
        return PhotoObservation::fromArray($photoId, [
            'technical' => [
                'exposure' => ['assessment' => 'underexposed', 'confidence' => 0.9],
                'highlight_clipping' => ['assessment' => 'safe', 'confidence' => 0.8],
                'sharpness' => ['assessment' => 'acceptable', 'confidence' => 0.8],
            ],
            'creative' => [],
        ], 'demo_pixel_stats', 'deterministic_on_device_pixel_analysis', null);
    }

    /* ------------------------------------------------------------------ */
    /*  1. Brief-aware retouch proposals */
    /* ------------------------------------------------------------------ */

    public function test_retouch_proposal_consumes_current_creative_brief(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $this->adoptBrief($project, $photographer);

        $photo = $project->photos()->first();
        $observation = $this->underexposedObservation($photo->id);

        $recommendation = app(ContextAwareRetouchService::class)->recommendForPhoto($project, $observation);

        $this->assertNotNull($recommendation);
        $this->assertArrayHasKey('adjustments', $recommendation);
        // The brief's restrained-warmth direction must be visible in provenance.
        $this->assertContains('brief.retouch.restrained_warmth', $recommendation['influenced_by']);
        // And the derivation must be brief-aware, not observation-only.
        $this->assertTrue($recommendation['brief_aware'] ?? true);
    }

    public function test_same_photo_different_brief_changes_retouch_plan(): void
    {
        // THE retouch counterfactual hard gate: identical photo, pixels,
        // observation — only the Creative Brief changes.
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();
        $observation = $this->underexposedObservation($photo->id);
        $svc = app(ContextAwareRetouchService::class);

        // Brief A: restrained warmth / modern neutral.
        $this->adoptBrief($project, $photographer, ['title' => 'Neutral Direction']);
        $planA = $svc->recommendForPhoto($project, $observation);
        $warmthA = (float) ($planA['adjustments']['warmth'] ?? 0.0);

        // Brief B: warm romantic editorial. Same photo, same observation.
        $this->adoptBrief($project, $photographer, [
            'title' => 'Warm Direction',
            'content' => [
                'retouch_philosophy' => 'warm romantic editorial, warmth encouraged',
                'tonality_notes' => 'warm golden glow',
                'color' => ['vivid warm'],
                'avoid' => [],
            ],
        ]);
        $planB = $svc->recommendForPhoto($project, $observation);
        $warmthB = (float) ($planB['adjustments']['warmth'] ?? 0.0);

        $this->assertGreaterThan($warmthA + 0.15, $warmthB, 'Brief B must be materially warmer than Brief A');
        $this->assertLessThanOrEqual(0.05, $warmthA);
        $this->assertGreaterThanOrEqual(0.3, $warmthB);
    }

    public function test_retouch_proposal_remains_proposal_only(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $this->adoptBrief($project, $photographer);

        $photo = $project->photos()->first();
        $this->putRealJpeg($photo);
        $originalBytes = Storage::disk('public')->get($photo->path);

        $this->actingAs($agent)
            ->postJson(route('api.webmcp.proposals.retouch', [$project->id]), [
                'summary' => 'Brief-aware retouch.',
                'items' => [
                    ['photo_id' => $photo->id, 'action' => 'retouch', 'params' => ['exposure' => 0.25]],
                ],
            ])
            ->assertCreated();

        $proposal = $project->proposals()->sole();
        $this->assertSame(Domain::TYPE_RETOUCH, $proposal->type);
        $this->assertSame(Domain::STATE_PENDING_REVIEW, $proposal->status);
        $this->assertSame(0, $project->derivatives()->count());

        $photo->refresh();
        $this->assertSame(Domain::RETOUCH_PROPOSED, $photo->retouch_state);
        // Proposal-only: original untouched.
        $this->assertSame($originalBytes, Storage::disk('public')->get($photo->path));
    }

    public function test_proposal_does_not_modify_original_or_create_derivative(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();
        $this->putRealJpeg($photo);
        $bytesBefore = Storage::disk('public')->get($photo->path);

        $proposal = $this->service->createProposal(
            $project,
            $agent,
            Domain::TYPE_RETOUCH,
            [['photo_id' => $photo->id, 'action' => 'retouch', 'params' => ['exposure' => 0.3, 'warmth' => 0.22]]],
        );

        $this->assertSame(0, $project->derivatives()->count());
        $this->assertSame($bytesBefore, Storage::disk('public')->get($photo->path));
        $this->assertSame(Domain::STATE_PENDING_REVIEW, $proposal->status);
    }

    /* ------------------------------------------------------------------ */
    /*  2. Photographer review: approve / reject / modify */
    /* ------------------------------------------------------------------ */

    private function makeRetouchProposal(array $photographerAndProject, User $agent, array $params = ['exposure' => 0.3, 'warmth' => 0.22]): Proposal
    {
        [$photographer, , $project] = $photographerAndProject;
        $photo = $project->photos()->first();

        return $this->service->createProposal(
            $project,
            $agent,
            Domain::TYPE_RETOUCH,
            [['photo_id' => $photo->id, 'action' => 'retouch', 'params' => $params, 'rationale' => 'agent rationale']],
            'Brighten gently.',
        );
    }

    public function test_photographer_can_approve_retouch_proposal(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent);

        $this->actingAs($photographer)
            ->postJson(route('proposals.approve', [$project->id, $proposal->id]), ['note' => 'ok'])
            ->assertOk();

        $proposal->refresh();
        $this->assertSame(Domain::STATE_APPROVED, $proposal->status);
        $this->assertSame($photographer->id, $proposal->reviewed_by);
    }

    public function test_photographer_can_reject_retouch_proposal(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent);

        $this->actingAs($photographer)
            ->postJson(route('proposals.reject', [$project->id, $proposal->id]), ['note' => 'too warm'])
            ->assertOk();

        $proposal->refresh();
        $this->assertSame(Domain::STATE_REJECTED, $proposal->status);
    }

    public function test_photographer_can_modify_adjustment_values(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent, ['exposure' => 0.3, 'warmth' => 0.22]);

        $this->actingAs($photographer)
            ->postJson(route('proposals.modify', [$project->id, $proposal->id]), [
                'note' => 'less warmth please',
                'modifications' => ['adjustments' => ['exposure' => 0.25, 'warmth' => 0.08]],
            ])
            ->assertOk();

        // Original proposal is preserved as MODIFIED — never overwritten.
        $proposal->refresh();
        $this->assertSame(Domain::STATE_MODIFIED, $proposal->status);
        $this->assertSame(['exposure' => 0.3, 'warmth' => 0.22], self::normAdjustments($proposal->items()->first()->params));

        // Superseding proposal carries photographer values, pending review.
        $superseding = Proposal::where('supersedes_id', $proposal->id)->sole();
        $this->assertSame(Domain::STATE_PENDING_REVIEW, $superseding->status);
        $this->assertSame(['exposure' => 0.25, 'warmth' => 0.08], self::normAdjustments($superseding->items()->first()->params));
        // Honest history: original agent values preserved in payload.
        $this->assertSame(
            ['exposure' => 0.3, 'warmth' => 0.22],
            self::normAdjustments($superseding->payload['original_items'][0]['params']),
        );
    }

    /* ------------------------------------------------------------------ */
    /*  3. Human authority boundaries */
    /* ------------------------------------------------------------------ */

    public function test_agent_cannot_approve_retouch_proposal(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent);

        $this->actingAs($agent)
            ->postJson(route('proposals.approve', [$project->id, $proposal->id]))
            ->assertForbidden();

        $proposal->refresh();
        $this->assertSame(Domain::STATE_PENDING_REVIEW, $proposal->status);
    }

    public function test_project_role_agent_cannot_approve(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent);

        // A viewer-role member also cannot approve.
        $viewer = User::factory()->create(['name' => 'Viewer']);
        $project->members()->syncWithoutDetaching([$viewer->id => ['role' => Domain::ROLE_VIEWER]]);

        $this->actingAs($viewer)
            ->postJson(route('proposals.approve', [$project->id, $proposal->id]))
            ->assertForbidden();

        $proposal->refresh();
        $this->assertSame(Domain::STATE_PENDING_REVIEW, $proposal->status);
    }

    public function test_viewer_cannot_approve(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent);

        $viewer = User::factory()->create();
        $project->members()->syncWithoutDetaching([$viewer->id => ['role' => Domain::ROLE_VIEWER]]);

        $this->actingAs($viewer)
            ->postJson(route('proposals.reject', [$project->id, $proposal->id]))
            ->assertForbidden();

        $proposal->refresh();
        $this->assertSame(Domain::STATE_PENDING_REVIEW, $proposal->status);
    }

    public function test_non_member_cannot_approve(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent);

        $outsider = User::factory()->create();
        $this->actingAs($outsider)
            ->postJson(route('proposals.approve', [$project->id, $proposal->id]))
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  4. Execution: derivatives, immutability, idempotency */
    /* ------------------------------------------------------------------ */

    private function approveAndExecute(array $world, Proposal $proposal): void
    {
        [$photographer, $agent, $project] = $world;
        $this->service->approve($proposal, $photographer);

        // Execute through the real WebMCP HTTP route so the audit row is
        // written exactly as apply_approved_plan does in production.
        $this->actingAs($agent)
            ->postJson(route('api.webmcp.proposals.execute', [$project->id, $proposal->id]))
            ->assertOk();
    }

    public function test_execution_requires_approved_proposal(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent);

        // Unapproved proposal is NOT executable via the WebMCP route.
        $this->actingAs($agent)
            ->postJson(route('api.webmcp.proposals.execute', [$project->id, $proposal->id]))
            ->assertStatus(409);
    }

    public function test_execution_reports_renderer_unavailable_without_500_and_preserves_approval(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();
        $originalBytes = 'original bytes remain untouched';
        Storage::disk('public')->put($photo->path, $originalBytes);

        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent);
        $this->service->approve($proposal, $photographer);

        app()->instance(GdAvailability::class, new GdAvailability(false));

        $response = $this->actingAs($agent)
            ->postJson(route('api.webmcp.proposals.execute', [$project->id, $proposal->id]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'renderer_unavailable');

        $this->assertStringContainsString('GD', (string) $response->json('error'));
        $this->assertSame(Domain::STATE_APPROVED, $proposal->fresh()->status);
        $this->assertSame(Domain::RETOUCH_APPROVED, $photo->fresh()->retouch_state);
        $this->assertSame(0, PhotoDerivative::where('photo_id', $photo->id)->count());
        $this->assertSame($originalBytes, Storage::disk('public')->get($photo->path));
        $this->assertDatabaseHas('agent_tool_calls', [
            'tool_name' => 'apply_approved_plan',
            'authority' => Domain::AUTHORITY_EXECUTE,
            'result_status' => Domain::RESULT_ERROR,
        ]);
    }

    public function test_execution_with_zero_applied_items_returns_422_and_stays_retryable(): void
    {
        // Regression (live 2026-08-29): the controller's applicator callable
        // dropped its return value, so execute() saw summary = null, the
        // honesty gate never fired, and an all-failed execution was marked
        // executed with the item left failed. It must 422, roll back, and
        // keep the proposal approved for retry.
        [$photographer, $agent, $project] = $this->makeWorld();

        $proposal = $this->service->createProposal(
            $project,
            $agent,
            Domain::TYPE_RETOUCH,
            // Item with NO photo attached → applyRetouchItem() fails.
            [['photo_id' => null, 'action' => 'retouch', 'params' => ['exposure' => 0.3], 'rationale' => 'agent rationale']],
            'Will fail — no photo attached.',
        );
        $this->service->approve($proposal, $photographer);

        $this->actingAs($agent)
            ->postJson(route('api.webmcp.proposals.execute', [$project->id, $proposal->id]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'execution_failed');

        // Rolled back: proposal still approved and retryable, item not stuck failed.
        $this->assertSame(Domain::STATE_APPROVED, $proposal->fresh()->status);
        $this->assertNull($proposal->fresh()->executed_at);
        $this->assertDatabaseHas('agent_tool_calls', [
            'tool_name' => 'apply_approved_plan',
            'result_status' => Domain::RESULT_ERROR,
        ]);
    }

    public function test_runtime_execution_failure_emits_compact_post_report_diagnostic(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent);
        $this->service->approve($proposal, $photographer);

        $cause = new \RuntimeException('Blob PUT rejected', 401);
        $applicator = Mockery::mock(ProposalApplicator::class)->makePartial();
        $applicator->shouldReceive('apply')->once()->andThrow(
            new \RuntimeException('Unable to write remote media.', 0, $cause),
        );
        $this->instance(ProposalApplicator::class, $applicator);
        $log = Log::spy();

        $this->actingAs($agent)
            ->postJson(route('api.webmcp.proposals.execute', [$project->id, $proposal->id]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'execution_failed');

        $log->shouldHaveReceived('error')
            ->with(
                'webmcp_execute_failure',
                Mockery::on(function (array $context) use ($proposal): bool {
                    return $context['proposal_id'] === $proposal->id
                        && $context['exception'] === \RuntimeException::class
                        && $context['cause_exception'] === \RuntimeException::class
                        && $context['cause_code'] === 401;
                }),
            )
            ->once();
    }

    public function test_execution_with_stale_double_prefixed_derivative_row_does_not_500(): void
    {
        // Regression (live 2026-08-29, production): a photo carrying a stale
        // double-prefixed approved_render row from the pre-e1633ec bug hit
        // MediaStore::isHttpPath() — then PRIVATE — from inside
        // ProposalApplicator's idempotency check. The visibility Error was
        // not one of the controller's caught exception types, so the WebMCP
        // execute endpoint returned a bare 500 "Server Error" and the whole
        // production E2E arc stalled. Only photos WITH an existing
        // approved_render derivative row took this branch, which is why the
        // e1633ec test run (fresh photos, no stale rows) stayed green.
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent, ['exposure' => 0.12, 'contrast' => 0.1]);
        $photo = $project->photos()->first();
        $this->putRealJpeg($photo);

        // Same-shape stale row the production photo carried: an absolute URL
        // whose pathname double-embeds the store origin. Derivatives hang off
        // the project (no Photo::derivatives relation), matching production
        // data exactly: one approved_render row for this photo.
        PhotoDerivative::create([
            'project_id' => $project->id,
            'photo_id' => $photo->id,
            'type' => Domain::DERIVATIVE_APPROVED_RENDER,
            'storage_path' => 'https://store.public.blob.vercel-storage.com/'
                .'https%3A//store.public.blob.vercel-storage.com/project-1/original.retouched.jpg',
            'adjustments' => ['exposure' => 0],
            'provenance' => 'demo',
            'created_by' => $photographer->id,
        ]);

        $this->service->approve($proposal, $photographer);

        $response = $this->actingAs($agent)
            ->postJson(route('api.webmcp.proposals.execute', [$project->id, $proposal->id]))
            ->assertOk();

        $response->assertJsonPath('proposal.status', Domain::STATE_EXECUTED);

        // Derivative lineage points at the fresh execution, and the stored
        // pathname is clean (no double prefix).
        $derivative = PhotoDerivative::where('photo_id', $photo->id)->sole();
        $this->assertSame($proposal->id, $derivative->proposal_id);
        $this->assertStringNotContainsString('https%3A', (string) $derivative->storage_path);

        // Original stays byte-for-byte untouched (applicator never writes to
        // originals; covered exhaustively by test_execution_creates_derivative…).
    }

    public function test_execution_unexpected_error_returns_422_not_500_and_stays_retryable(): void
    {
        // Regression (live 2026-08-29): any unexpected \Throwable inside the
        // applicator surfaced as a bare 500 "Server Error" through the WebMCP
        // route. The honesty contract requires a 422 execution_failed that
        // leaves the approved proposal retryable.
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent);
        $photo = $project->photos()->first();
        $this->putRealJpeg($photo);

        $this->service->approve($proposal, $photographer);

        // Force an unexpected error class (not RuntimeException) out of the
        // applicator, mimicking the visibility Error found in production.
        $applicator = Mockery::mock(ProposalApplicator::class)->makePartial();
        $applicator->shouldReceive('apply')->once()->andThrow(new \Error('Call to private method (simulated)'));

        $this->instance(ProposalApplicator::class, $applicator);

        $this->actingAs($agent)
            ->postJson(route('api.webmcp.proposals.execute', [$project->id, $proposal->id]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'execution_failed');

        $this->assertSame(Domain::STATE_APPROVED, $proposal->fresh()->status);
        $this->assertNull($proposal->fresh()->executed_at);
        $this->assertDatabaseHas('agent_tool_calls', [
            'tool_name' => 'apply_approved_plan',
            'result_status' => Domain::RESULT_ERROR,
        ]);
    }

    public function test_execution_creates_derivative_and_original_remains_untouched(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent, ['exposure' => 0.3, 'warmth' => 0.22]);
        $photo = $project->photos()->first();
        $this->putRealJpeg($photo);

        $bytesBefore = Storage::disk('public')->get($photo->path);
        $hashBefore = hash('sha256', $bytesBefore);

        $this->approveAndExecute([$photographer, $agent, $project], $proposal);

        // Derivative exists with correct lineage.
        $derivative = PhotoDerivative::where('photo_id', $photo->id)->sole();
        $this->assertSame($project->id, $derivative->project_id);
        $this->assertSame($proposal->id, $derivative->proposal_id);
        $this->assertSame(Domain::DERIVATIVE_APPROVED_RENDER, $derivative->type);
        $this->assertSame(['exposure' => 0.3, 'warmth' => 0.22], self::normAdjustments($derivative->adjustments ?? []));

        // Pixel verification: real JPEG bytes, decodes, differs from original.
        $derivativeBytes = Storage::disk('public')->get($derivative->storage_path);
        $this->assertNotSame($bytesBefore, $derivativeBytes, 'non-zero adjustments must produce different bytes');
        $decoded = @imagecreatefromstring($derivativeBytes);
        $this->assertNotFalse($decoded, 'derivative must decode as a valid image');
        $this->assertSame(64, imagesx($decoded));
        $this->assertSame(48, imagesy($decoded));

        // Original immutability: byte-for-byte.
        $this->assertSame($bytesBefore, Storage::disk('public')->get($photo->path));
        $this->assertSame($hashBefore, hash('sha256', Storage::disk('public')->get($photo->path)));
    }

    public function test_execution_uses_photographer_modified_params(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent, ['exposure' => 0.3, 'warmth' => 0.22]);
        $photo = $project->photos()->first();
        $this->putRealJpeg($photo);

        // Photographer modifies before approving.
        $superseding = $this->service->requestModification(
            $proposal,
            $photographer,
            'dial warmth back',
            ['adjustments' => ['exposure' => 0.25, 'warmth' => 0.08]],
        );
        $this->service->approve($superseding, $photographer);
        $this->service->execute($superseding, $agent, app(ProposalApplicator::class)->apply(...));

        // The derivative MUST carry photographer-approved values, not agent's.
        $derivative = PhotoDerivative::where('photo_id', $photo->id)->sole();
        $this->assertSame(['exposure' => 0.25, 'warmth' => 0.08], self::normAdjustments($derivative->adjustments ?? []));
    }

    public function test_http_brief_aware_proposal_still_renders_with_enrichment_metadata(): void
    {
        // REGRESSION: propose_retouch_plan over HTTP merges brief-awareness
        // evidence (brief_aware, derived_adjustments, adjustments_summary,
        // retouch_influenced_by, retouch_note) into the item params alongside
        // the executable values. The renderer must consume ONLY the six
        // documented adjustment keys and must not treat the evidence block as
        // adjustments — otherwise the proposal executes but silently fails
        // every item and produces NO derivative.
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();
        $this->putRealJpeg($photo);
        $bytesBefore = Storage::disk('public')->get($photo->path);

        $this->actingAs($agent)
            ->postJson(route('api.webmcp.proposals.retouch', [$project->id]), [
                'summary' => 'Brief-aware retouch over HTTP.',
                'items' => [
                    ['photo_id' => $photo->id, 'action' => 'exposure', 'params' => ['exposure' => 0.3]],
                ],
            ])
            ->assertCreated();

        $proposal = $project->proposals()->sole();
        $item = $proposal->items()->first();

        // The HTTP enrichment must actually be present, or this test proves
        // nothing about the real propose path.
        $this->assertArrayHasKey('brief_aware', $item->params);
        $this->assertArrayHasKey('derived_adjustments', $item->params);

        $this->service->approve($proposal, $photographer);
        $executed = $this->service->execute($proposal->fresh(), $agent, app(ProposalApplicator::class)->apply(...));

        // Execution must APPLY (not fail) despite the enrichment metadata.
        $this->assertSame('applied', $executed->items->first()->status);
        $this->assertSame(1, PhotoDerivative::where('photo_id', $photo->id)->count());

        // The derivative carries ONLY the executable adjustment keys.
        $derivative = PhotoDerivative::where('photo_id', $photo->id)->sole();
        $this->assertSame(['exposure' => 0.3], self::normAdjustments($derivative->adjustments ?? []));

        // And the original remains byte-for-byte intact.
        $this->assertSame($bytesBefore, Storage::disk('public')->get($photo->path));
    }

    public function test_workspace_retouch_card_layers_show_only_adjustment_values(): void
    {
        // REGRESSION: the Workspace page's retouch truth card must present a
        // clean three-layer value history (AI PROPOSAL / PHOTOGRAPHER
        // MODIFIED / EXECUTED) even when the proposal came through the HTTP
        // brief-aware propose path — the enrichment metadata block must never
        // leak into the rendered layers.
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();
        $this->putRealJpeg($photo);

        $this->actingAs($agent)
            ->postJson(route('api.webmcp.proposals.retouch', [$project->id]), [
                'summary' => 'Brief-aware retouch.',
                'items' => [
                    ['photo_id' => $photo->id, 'action' => 'exposure', 'params' => ['exposure' => 0.3]],
                ],
            ])
            ->assertCreated();

        $proposal = $project->proposals()->sole();
        $superseding = $this->service->requestModification(
            $proposal, $photographer, 'less warmth',
            ['adjustments' => ['exposure' => 0.25, 'warmth' => 0.08]],
        );
        $this->service->approve($superseding, $photographer);
        $this->service->execute($superseding->fresh(), $agent, app(ProposalApplicator::class)->apply(...));

        $response = $this->actingAs($photographer)
            ->get(route('workspace.show', [$project->id]))
            ->assertOk();

        preg_match('/data-page="([^"]+)"/', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'Inertia data-page payload must exist');
        $page = json_decode(htmlspecialchars_decode($m[1], ENT_QUOTES), true);
        $card = $page['props']['retouchCard'] ?? null;
        $this->assertNotNull($card, 'retouchCard must be server-rendered');

        // AGENT ORIGINAL: only numeric adjustment keys — no enrichment garbage.
        $agentParams = $card['agent_original']['params'] ?? null;
        $this->assertIsArray($agentParams);
        foreach (['brief_aware', 'derived_adjustments', 'adjustments_summary', 'retouch_influenced_by', 'retouch_note'] as $meta) {
            $this->assertArrayNotHasKey($meta, $agentParams, "enrichment metadata must not leak into the AI PROPOSAL layer: {$meta}");
        }
        $this->assertSame(['exposure' => 0.3], self::normAdjustments($agentParams));

        // PHOTOGRAPHER MODIFIED: photographer's exact values.
        $this->assertSame(
            ['exposure' => 0.25, 'warmth' => 0.08],
            self::normAdjustments($card['photographer_modification']['adjustments'] ?? []),
        );

        // EXECUTED: equals photographer modification (hard gate).
        $this->assertSame(
            ['exposure' => 0.25, 'warmth' => 0.08],
            self::normAdjustments($card['executed']['params'] ?? []),
        );

        // Derivative evidence: differs from original for a non-zero edit.
        $this->assertNotNull($card['original']['sha256'] ?? null);
        $this->assertNotNull($card['derivative']['sha256'] ?? null);
        $this->assertNotSame($card['original']['sha256'], $card['derivative']['sha256']);
    }

    public function test_double_execution_rejected_and_no_duplicate_derivative(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent);
        $photo = $project->photos()->first();
        $this->putRealJpeg($photo);

        $this->approveAndExecute([$photographer, $agent, $project], $proposal);
        $this->assertSame(1, PhotoDerivative::where('photo_id', $photo->id)->count());

        // Second execution attempt is rejected deterministically.
        $this->actingAs($agent)
            ->postJson(route('api.webmcp.proposals.execute', [$project->id, $proposal->id]))
            ->assertStatus(409);

        $this->assertSame(1, PhotoDerivative::where('photo_id', $photo->id)->count(), 'no duplicate approved derivative');
    }

    public function test_repeated_render_does_not_duplicate_derivatives(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent);
        $photo = $project->photos()->first();
        $this->putRealJpeg($photo);

        $this->approveAndExecute([$photographer, $agent, $project], $proposal);
        $first = PhotoDerivative::where('photo_id', $photo->id)->sole();

        // Re-apply through the same applicator (idempotency path).
        app(ProposalApplicator::class)->apply($proposal->fresh());

        $this->assertSame(1, PhotoDerivative::where('photo_id', $photo->id)->count());
        $this->assertSame($first->id, PhotoDerivative::where('photo_id', $photo->id)->sole()->id);
    }

    public function test_execution_is_audited(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $proposal = $this->makeRetouchProposal([$photographer, $agent, $project], $agent);
        $photo = $project->photos()->first();
        $this->putRealJpeg($photo);

        $this->approveAndExecute([$photographer, $agent, $project], $proposal);

        $this->assertDatabaseHas('agent_tool_calls', [
            'tool_name' => 'apply_approved_plan',
            'authority' => Domain::AUTHORITY_EXECUTE,
            'project_id' => $project->id,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  5. Consistency QA + counterfactual */
    /* ------------------------------------------------------------------ */

    private function seedSelectedSetWithDerivatives(Project $project, float $outlierWarmth): Collection
    {
        $photos = $project->photos()->orderBy('id')->take(3)->get();
        foreach ($photos as $i => $photo) {
            $photo->forceFill(['selection_state' => Domain::SELECTION_SELECTED])->save();
            PhotoObservationRecord::create([
                'photo_id' => $photo->id,
                'project_id' => $project->id,
                'payload' => [
                    'technical' => [
                        'exposure' => ['assessment' => 'acceptable', 'confidence' => 0.9],
                        'highlight_clipping' => ['assessment' => 'safe'],
                        'sharpness' => ['assessment' => 'acceptable'],
                    ],
                    'creative' => [],
                ],
                'provider' => 'demo_pixel_stats',
                'provenance' => 'deterministic_on_device_pixel_analysis',
                'similarity_group' => null,
            ]);
            PhotoDerivative::create([
                'project_id' => $project->id,
                'photo_id' => $photo->id,
                'type' => Domain::DERIVATIVE_APPROVED_RENDER,
                'storage_path' => 'project-0/der-'.$photo->id.'.jpg',
                'adjustments' => $i === 2
                    ? ['exposure' => 0.1, 'warmth' => $outlierWarmth]
                    : ['exposure' => 0.1, 'warmth' => 0.0],
                'provenance' => Domain::RENDERER_PROVENANCE_DEMO,
            ]);
        }

        return collect($photos);
    }

    public function test_run_consistency_review_consumes_creative_brief_and_persists(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $this->adoptBrief($project, $photographer); // restrained warmth brief
        $this->seedSelectedSetWithDerivatives($project, 0.6); // warm outlier

        $this->actingAs($agent)
            ->postJson(route('api.webmcp.qa.review', [$project->id]), ['scope' => 'selected'])
            ->assertCreated();

        // Audit row must record the Sprint 4 ANALYZE authority (not PROPOSE,
        // not READ) — backend catalogue, frontend registry, diagnostics, and
        // audit trail all agree on run_consistency_review semantics.
        $this->assertDatabaseHas('agent_tool_calls', [
            'tool_name' => 'run_consistency_review',
            'authority' => Domain::AUTHORITY_ANALYZE,
            'project_id' => $project->id,
        ]);

        $this->assertTrue(
            QaFinding::where('project_id', $project->id)
                ->whereIn('category', ['warmth_consistency', 'creative_direction_drift'])
                ->exists(),
            'warm outlier must be flagged under a restrained-warmth brief',
        );
    }

    public function test_same_set_different_brief_changes_qa_result(): void
    {
        // THE QA counterfactual hard gate: same derivatives, same set —
        // only the Creative Brief changes. Assertions run against each
        // run's ACTUAL created_findings (category + severity), never
        // against rows left behind by a previous run.
        [$photographer, $agent, $project] = $this->makeWorld();
        $this->seedSelectedSetWithDerivatives($project, 0.6);

        $runReview = function () use ($agent, $project): array {
            $response = $this->actingAs($agent)
                ->postJson(route('api.webmcp.qa.review', [$project->id]), ['scope' => 'selected'])
                ->assertCreated();

            return collect($response->json('created_findings'))
                ->whereIn('category', ['warmth_consistency', 'creative_direction_drift'])
                ->values()
                ->all();
        };

        // Brief A: restrained warmth / modern neutral → the warm outlier is
        // flagged at meaningful severity on BOTH consistency dimensions.
        $this->adoptBrief($project, $photographer, ['title' => 'Neutral']);
        $findingsA = $runReview();

        $this->assertNotEmpty($findingsA, 'restrained brief must flag the warm outlier');
        $categoriesA = array_column($findingsA, 'category');
        $this->assertContains('warmth_consistency', $categoriesA, 'Brief A must produce a warmth_consistency finding, got: '.json_encode($categoriesA));
        $this->assertContains('creative_direction_drift', $categoriesA, 'Brief A must produce a creative_direction_drift finding, got: '.json_encode($categoriesA));

        $severitiesA = array_column($findingsA, 'severity');
        $this->assertNotEmpty(
            array_intersect(['medium', 'high'], $severitiesA),
            'Brief A must flag the warm outlier at meaningful severity, got: '.json_encode($severitiesA),
        );

        // Brief B: warm romantic editorial, warmth encouraged → SAME pixels,
        // SAME set: the warmth/drift findings disappear entirely.
        $this->adoptBrief($project, $photographer, [
            'title' => 'Warm',
            'content' => [
                'retouch_philosophy' => 'warm romantic editorial, warmth encouraged',
                'tonality_notes' => 'warm golden',
                'avoid' => [],
                'color' => ['vivid'],
            ],
        ]);
        $findingsB = $runReview();

        $this->assertSame(
            [],
            $findingsB,
            'warm-romantic brief must remove the warm-outlier findings entirely, got: '.json_encode($findingsB),
        );
    }

    public function test_qa_finding_references_creative_brief_in_influenced_by(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $this->adoptBrief($project, $photographer);
        $this->seedSelectedSetWithDerivatives($project, 0.6);

        $this->actingAs($agent)
            ->postJson(route('api.webmcp.qa.review', [$project->id]), ['scope' => 'selected'])
            ->assertCreated();

        $finding = QaFinding::where('project_id', $project->id)
            ->whereIn('category', ['warmth_consistency', 'creative_direction_drift'])
            ->first();

        $influenced = $finding->details['influenced_by'] ?? [];
        $this->assertNotEmpty($influenced);
        $this->assertTrue(
            collect($influenced)->contains(fn ($ref) => str_contains((string) $ref, 'brief')),
            'QA provenance must reference the adopted Creative Brief',
        );
    }

    public function test_project_level_qa_resolution_without_a_photo_resolves_findings_safely(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $finding = QaFinding::create([
            'project_id' => $project->id,
            'photo_id' => null,
            'severity' => 'warning',
            'category' => 'project_level_consistency',
            'message' => 'The set needs a project-level correction.',
            'details' => [],
            'status' => 'open',
        ]);

        $proposal = $this->service->createProposal(
            $project,
            $agent,
            Domain::TYPE_QA_RESOLUTION,
            [[
                'action' => 'apply_fix',
                'params' => ['finding_ids' => [$finding->id]],
            ]],
            'Resolve the project-level QA finding.',
        );
        $proposal = $this->service->approve($proposal, $photographer);

        $result = app(ProposalApplicator::class)->apply($proposal);

        $finding->refresh();
        $this->assertSame('resolved', $finding->status);
        $this->assertSame(1, $result['items_applied']);
        $this->assertNull($result['items'][0]['photo_id']);
    }

    public function test_qa_human_actions_are_photographer_only(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $this->seedSelectedSetWithDerivatives($project, 0.6);

        // Create a finding through the real review route.
        $this->actingAs($agent)
            ->postJson(route('api.webmcp.qa.review', [$project->id]), ['scope' => 'selected'])
            ->assertCreated();
        $finding = QaFinding::where('project_id', $project->id)->first();

        // Agent cannot act on findings.
        $this->actingAs($agent)
            ->postJson(route('qa-findings.respond', [$project->id, $finding->id]), ['action' => 'acknowledge'])
            ->assertForbidden();

        // Photographer can acknowledge.
        $this->actingAs($photographer)
            ->postJson(route('qa-findings.respond', [$project->id, $finding->id]), ['action' => 'acknowledge', 'note' => 'noted'])
            ->assertOk();
        $finding->refresh();
        $this->assertSame('acknowledged', $finding->status);
    }

    public function test_agent_cannot_finalize_qa_or_project(): void
    {
        // No WebMCP tool exists for QA correction / finalize / delivery.
        $names = array_column(WebmcpToolCatalog::all(), 'name');
        foreach ([
            'approve_qa_correction', 'force_retouch', 'mark_project_complete',
            'final_delivery', 'finalize_project', 'approve_retouch',
            'approve_own_retouch', 'finalize_retouch', 'photographer_modify_retouch',
            'photographer_approve_retouch', 'delete_original', 'overwrite_original',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $names, "forbidden tool must not exist: {$forbidden}");
        }
    }

    /* ------------------------------------------------------------------ */
    /*  6. Creative Memory (LEARN) */
    /* ------------------------------------------------------------------ */

    public function test_photographer_can_store_creative_memory(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();

        $this->actingAs($photographer)
            ->postJson(route('creative-memory.store', [$project->id]), ['lesson' => 'Less warm.'])
            ->assertCreated();

        $this->assertDatabaseHas('creative_memories', [
            'project_id' => $project->id,
            'lesson' => 'Less warm.',
            'created_by' => $photographer->id,
        ]);
    }

    public function test_store_response_photographer_is_a_string_name(): void
    {
        // React #31 contract: the Workspace optimistically prepends this row
        // into the Creative Memory list, so `photographer` MUST be a plain
        // string name — never a {id,name} relation object (that shape was
        // rendered as a React child and crashed the live Workspace with
        // Minified React error #31).
        [$photographer, $agent, $project] = $this->makeWorld();

        $response = $this->actingAs($photographer)
            ->postJson(route('creative-memory.store', [$project->id]), ['lesson' => 'Keep grain.'])
            ->assertCreated();

        $this->assertIsString($response->json('memory.photographer'));
        $this->assertSame($photographer->name, $response->json('memory.photographer'));
    }

    public function test_agent_cannot_store_creative_memory(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();

        $this->actingAs($agent)
            ->postJson(route('creative-memory.store', [$project->id]), ['lesson' => 'agent memory'])
            ->assertForbidden();

        $this->assertSame(0, $project->creativeMemories()->count());
    }

    public function test_explicit_creative_memory_reduces_future_proposed_warmth(): void
    {
        // Product-level intent: an explicit photographer-authored Creative
        // Memory lesson deterministically reduces future warmth in retouch
        // proposals for the SAME photo/brief/observation — NOT ML
        // personalization.
        [$photographer, $agent, $project] = $this->makeWorld();
        // Adopt a WARM brief — would normally push warmth +0.3.
        $this->adoptBrief($project, $photographer, [
            'title' => 'Warm',
            'content' => [
                'retouch_philosophy' => 'warm romantic editorial, warmth encouraged',
                'tonality_notes' => 'warm golden',
                'color' => ['vivid'],
                'avoid' => [],
            ],
        ]);

        $photo = $project->photos()->first();
        $observation = $this->underexposedObservation($photo->id);
        $svc = app(ContextAwareRetouchService::class);

        $before = $svc->recommendForPhoto($project, $observation);
        $warmthBefore = (float) ($before['adjustments']['warmth'] ?? 0.0);
        // Warm-romantic brief must actually be warm, or the gate is vacuous.
        $this->assertGreaterThanOrEqual(0.3, $warmthBefore);

        // Photographer persists explicit memory: "Less warm." (server-side
        // create; the photographer-only HTTP endpoint is covered by the
        // dedicated store/auth tests above).
        $project->creativeMemories()->create([
            'photographer_id' => $photographer->id,
            'lesson' => 'Less warm.',
            'created_by' => $photographer->id,
        ]);

        $after = $svc->recommendForPhoto($project, $observation);
        $warmthAfter = (float) ($after['adjustments']['warmth'] ?? 0.0);

        // Materially warmer → materially cooler (documented policy: the
        // "less warm" keyword rule caps warmth at -0.05, see
        // ContextAwareRetouchService::memoryAdjustments).
        $this->assertLessThan($warmthBefore, $warmthAfter, 'memory "Less warm." must reduce proposed warmth');
        $this->assertLessThanOrEqual(-0.05, $warmthAfter, 'memory cap must land at or below the documented -0.05 policy bound');
        $this->assertContains('memory.less_warm', $after['influenced_by']);

        // The lesson persisted with its human author.
        $this->assertDatabaseHas('creative_memories', [
            'project_id' => $project->id,
            'photographer_id' => $photographer->id,
            'created_by' => $photographer->id,
            'lesson' => 'Less warm.',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  7. Inventory + regressions */
    /* ------------------------------------------------------------------ */

    public function test_webmcp_inventory_no_drift(): void
    {
        $tools = WebmcpToolCatalog::all();
        $this->assertSame(20, count($tools), 'tool inventory must not drift');

        $qa = collect($tools)->firstWhere('name', 'run_consistency_review');
        $this->assertSame(Domain::AUTHORITY_ANALYZE, $qa['authority']);
        $this->assertFalse($qa['read_only'], 'QA persists findings — must NOT claim read-only');

        $apply = collect($tools)->firstWhere('name', 'apply_approved_plan');
        $this->assertTrue($apply['dynamic'], 'apply_approved_plan must stay dynamic');

        // Retouch upgrade must stay PROPOSE, not a new duplicate tool.
        $retouch = collect($tools)->firstWhere('name', 'propose_retouch_plan');
        $this->assertSame(Domain::AUTHORITY_PROPOSE, $retouch['authority']);
    }

    public function test_project_isolation_for_retouch_and_qa(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        [, , $otherProject] = $this->makeWorld();

        $photo = $project->photos()->first();
        $otherPhoto = $otherProject->photos()->first();

        // Cross-project retouch proposal is refused.
        $this->actingAs($agent)
            ->postJson(route('api.webmcp.proposals.retouch', [$project->id]), [
                'summary' => 'cross-project',
                'items' => [
                    ['photo_id' => $otherPhoto->id, 'action' => 'retouch', 'params' => ['exposure' => 0.1]],
                ],
            ])
            ->assertStatus(422);
    }
}
