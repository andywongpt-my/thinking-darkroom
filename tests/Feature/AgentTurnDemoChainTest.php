<?php

namespace Tests\Feature;

use App\Domain\Culling\PhotoObservation;
use App\Domain\Domain;
use App\Models\AgentToolCall;
use App\Models\PhotoObservationRecord;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Services\CreativeRoomService;
use App\Services\Culling\ContextAwareCullingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Demo-chain end-to-end: an agent turn triggered by a natural-language
 * message escalates to the real tool chain — per-photo keeper lists and,
 * for explicit cull intents, a persisted cull PROPOSAL awaiting approval.
 *
 * Authority invariants under test:
 *  - the turn NEVER changes selection_state (ANALYZE/PROPOSE only)
 *  - the cull proposal is pending_review and photographer-approvable
 *  - the audit trail records both agent_turn and propose_cull rows
 *  - non-intent messages keep the status-reply contract (no proposal)
 */
class AgentTurnDemoChainTest extends TestCase
{
    use RefreshDatabase;

    private function seedProjectWithObservations(): array
    {
        $photographer = User::factory()->create(['name' => 'Maya']);
        $agent = User::factory()->agent()->create(['name' => 'Darkroom Agent']);
        $project = Project::factory()->withPhotos(3)->create([
            'owner_id' => $photographer->id,
        ]);
        $project->members()->sync([
            $photographer->id => ['role' => Domain::ROLE_OWNER],
            $agent->id => ['role' => Domain::ROLE_AGENT],
        ]);

        $room = app(CreativeRoomService::class);
        $concept = $room->proposeConcept(
            $project,
            $photographer,
            null,
            'Documentary Intimacy',
            'Emotion-first documentary direction.',
            [
                'mood' => ['intimate', 'quiet', 'documentary'],
                'story' => 'Honest documentary intimacy.',
                'selection_priorities' => ['emotion' => 'primary', 'technical' => 'secondary'],
                'avoid' => ['overly posed expressions'],
                'retouch_philosophy' => 'natural skin, restrained warmth',
                'tonality_notes' => 'modern neutral',
                'color' => ['muted'],
            ],
        );
        $room->adoptConcept($project, $photographer, $concept);

        $culling = app(ContextAwareCullingService::class);
        $make = fn (int $photoId, array $technical, array $creative) => PhotoObservation::fromArray(
            $photoId,
            ['technical' => $technical, 'creative' => $creative],
            'demo_pixel_stats',
            'deterministic_on_device_pixel_analysis',
            null,
        );

        $photos = $project->photos()->orderBy('id')->get();
        $project->photos()->update(['selection_state' => Domain::SELECTION_UNREVIEWED]);
        $photos = $project->photos()->orderBy('id')->get();
        $shapes = [
            // strong keeper: sharp, candid, emotionally strong, on-brief mood
            [
                ['sharpness' => ['assessment' => 'sharp', 'confidence' => 0.9], 'exposure' => ['assessment' => 'acceptable', 'confidence' => 0.8], 'highlight_clipping' => ['assessment' => 'safe'], 'motion_blur' => ['assessment' => 'none', 'confidence' => 0.85]],
                ['expression' => 'joyful', 'candidness' => 'candid', 'environmental_storytelling' => 'strong', 'mood' => ['intimate'], 'compositional_fit' => 'fits', 'emotion_strength' => 'strong'],
            ],
            // reject candidate: soft, strong blur, overexposed, posed, off-mood
            [
                ['sharpness' => ['assessment' => 'soft', 'confidence' => 0.9], 'exposure' => ['assessment' => 'overexposed', 'confidence' => 0.8], 'highlight_clipping' => ['assessment' => 'risk'], 'motion_blur' => ['assessment' => 'strong', 'confidence' => 0.85]],
                ['expression' => 'neutral', 'candidness' => 'posed', 'environmental_storytelling' => 'weak', 'mood' => ['dramatic'], 'compositional_fit' => 'off', 'emotion_strength' => 'flat'],
            ],
            // mid keeper
            [
                ['sharpness' => ['assessment' => 'sharp', 'confidence' => 0.85], 'exposure' => ['assessment' => 'acceptable', 'confidence' => 0.8], 'highlight_clipping' => ['assessment' => 'safe'], 'motion_blur' => ['assessment' => 'none', 'confidence' => 0.8]],
                ['expression' => 'genuine', 'candidness' => 'mostly_candid', 'environmental_storytelling' => 'present', 'mood' => ['quiet'], 'compositional_fit' => 'fits', 'emotion_strength' => 'genuine'],
            ],
        ];

        foreach ($photos as $i => $photo) {
            [$technical, $creative] = $shapes[$i % count($shapes)];
            PhotoObservationRecord::create([
                'photo_id' => $photo->id,
                'project_id' => $project->id,
                'payload' => ['technical' => $technical, 'creative' => $creative],
                'provider' => 'demo_pixel_stats',
                'provenance' => 'deterministic_on_device_pixel_analysis',
                'similarity_group' => null,
            ]);
        }

        return [$photographer, $agent, $project];
    }

    private function postTurn($project, $photographer, string $body): TestResponse
    {
        $triggerId = $this->actingAs($photographer)
            ->postJson(route('agent-conversation.store', $project), [
                'body' => $body,
                'client_message_id' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->json('message.id');

        return $this->actingAs($photographer)
            ->postJson(route('agent-conversation.turn', $project), [
                'trigger_id' => $triggerId,
                'client_opt_in' => true,
            ]);
    }

    public function test_keeper_intent_lists_top_keepers_without_touching_selections(): void
    {
        [$photographer, $agent, $project] = $this->seedProjectWithObservations();

        $response = $this->postTurn($project, $photographer, 'Find the best keepers under "Documentary Intimacy".')
            ->assertOk();

        $body = (string) $response->json('message.body');
        $this->assertStringContainsString('keeper candidate', $body);
        $this->assertStringContainsString('the photographer decides', $body);
        $this->assertStringContainsString('Documentary Intimacy', $body);

        // The turn is ANALYZE-only: every photo keeps its original state.
        $project->photos()->get()->each(fn ($p) => $this->assertSame(
            Domain::SELECTION_UNREVIEWED,
            $p->fresh()->selection_state,
        ));

        $audit = AgentToolCall::query()
            ->where('project_id', $project->id)
            ->where('tool_name', 'agent_turn')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('keepers', $audit->output_summary['turn_intent']);
        $this->assertNull($audit->output_summary['cull_proposal_id']);
    }

    public function test_cull_intent_creates_a_pending_proposal_the_photographer_can_approve(): void
    {
        [$photographer, $agent, $project] = $this->seedProjectWithObservations();

        $response = $this->postTurn($project, $photographer, 'Please propose a cull for the weak frames.')
            ->assertOk();

        $body = (string) $response->json('message.body');
        $this->assertStringContainsString('waiting for your approval', $body);

        // Exactly one pending cull proposal, created by the agent account.
        $proposal = Proposal::query()
            ->where('project_id', $project->id)
            ->where('type', Domain::TYPE_CULL)
            ->where('status', Domain::STATE_PENDING_REVIEW)
            ->firstOrFail();
        $this->assertSame($agent->id, $proposal->created_by);
        $this->assertSame('agent_turn', $proposal->payload['created_via'] ?? null);
        $this->assertGreaterThanOrEqual(1, $proposal->items()->count());

        // Items carry recommendation evidence (context-aware cull).
        $this->assertDatabaseHas('proposal_items', [
            'proposal_id' => $proposal->id,
            'action' => 'cull',
        ]);

        // The turn itself never touched the authoritative selection state.
        $project->photos()->get()->each(fn ($p) => $this->assertSame(
            Domain::SELECTION_UNREVIEWED,
            $p->fresh()->selection_state,
        ));

        // The PROPOSE action is audited under propose_cull.
        $this->assertDatabaseHas('agent_tool_calls', [
            'project_id' => $project->id,
            'tool_name' => 'propose_cull',
            'authority' => Domain::AUTHORITY_PROPOSE,
        ]);

        // The photographer can approve through the HUMAN-ONLY review path.
        $this->actingAs($photographer)
            ->postJson(route('proposals.approve', [$project->id, $proposal->id]))
            ->assertOk();
        $this->assertSame(
            Domain::STATE_APPROVED,
            $proposal->fresh()->status,
        );
    }

    public function test_plain_status_message_keeps_the_status_reply_and_creates_no_proposal(): void
    {
        [$photographer, $agent, $project] = $this->seedProjectWithObservations();

        $response = $this->postTurn($project, $photographer, 'Please review this project.')
            ->assertOk();

        $body = (string) $response->json('message.body');
        $this->assertStringContainsString('I reviewed the project:', $body);
        $this->assertStringNotContainsString('waiting for your approval', $body);

        $this->assertSame(0, Proposal::query()->where('project_id', $project->id)->count());
    }
}
