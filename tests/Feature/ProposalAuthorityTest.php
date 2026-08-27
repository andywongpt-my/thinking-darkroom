<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\Photo;
use App\Models\PhotographerDecision;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\QaFinding;
use App\Models\User;
use App\Services\ProposalApplicator;
use App\Services\ProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests the creative-authority state machine at the service layer, plus
 * project isolation and proposal-boundary invariants.
 */
class ProposalAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private ProposalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProposalService::class);
    }

    private function makeWorld(): array
    {
        $photographer = User::factory()->create(['name' => 'Photographer']);
        $agent = User::factory()->create(['is_agent' => true, 'name' => 'Agent']);
        $project = Project::factory()->withPhotos(6)->create(['owner_id' => $photographer->id]);
        $project->members()->syncWithoutDetaching([
            $photographer->id => ['role' => Domain::ROLE_OWNER],
            $agent->id => ['role' => Domain::ROLE_AGENT],
        ]);
        // Deterministic starting state for the creative-authority assertions.
        $project->photos()->update([
            'selection_state' => Domain::SELECTION_UNREVIEWED,
            'retouch_state' => Domain::RETOUCH_NONE,
        ]);

        return [$photographer, $agent, $project];
    }

    /** Agent can create a proposal. */
    public function test_agent_can_create_proposal(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();

        $proposal = $this->service->createProposal(
            $project,
            $agent,
            Domain::TYPE_CULL,
            [
                ['photo_id' => $photo->id, 'action' => 'cull', 'rationale' => 'motion blur'],
            ],
            'Cull soft frames.',
        );

        $this->assertDatabaseHas('proposals', [
            'id' => $proposal->id,
            'type' => Domain::TYPE_CULL,
            'status' => Domain::STATE_PENDING_REVIEW,
            'created_by' => $agent->id,
        ]);
        $this->assertSame(1, $proposal->items()->count());
        $this->assertSame('proposed', $proposal->items()->first()->status);
    }

    /** Creating a proposal does NOT apply any decision. */
    public function test_creating_proposal_does_not_apply_decision(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();
        $this->assertNotSame(Domain::SELECTION_CULLED, $photo->selection_state);

        $proposal = $this->service->createProposal(
            $project,
            $agent,
            Domain::TYPE_CULL,
            [
                ['photo_id' => $photo->id, 'action' => 'cull'],
            ],
            'Cull this frame.',
        );

        $photo->refresh();
        $this->assertSame(Domain::SELECTION_UNREVIEWED, $photo->selection_state);
        $this->assertSame(Domain::STATE_PENDING_REVIEW, $proposal->status);
        $this->assertSame(0, PhotographerDecision::count());
    }

    /** Photographer can approve a proposal. */
    public function test_photographer_can_approve_proposal(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();

        $proposal = $this->service->createProposal(
            $project,
            $agent,
            Domain::TYPE_CULL,
            [['photo_id' => $photo->id, 'action' => 'cull']],
        );

        $approved = $this->service->approve($proposal, $photographer, 'OK, cull it.');

        $this->assertSame(Domain::STATE_APPROVED, $approved->status);
        $this->assertNotNull($approved->reviewed_at);
        $this->assertSame($photographer->id, $approved->reviewed_by);
        $this->assertDatabaseHas('photographer_decisions', [
            'proposal_id' => $proposal->id,
            'decision' => 'approve',
            'photographer_id' => $photographer->id,
        ]);

        // Approval alone still does NOT apply creative state — only execution does.
        $photo->refresh();
        $this->assertSame(Domain::SELECTION_UNREVIEWED, $photo->selection_state);
    }

    /** A rejected proposal cannot execute. */
    public function test_rejected_proposal_cannot_execute(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();

        $proposal = $this->service->createProposal(
            $project,
            $agent,
            Domain::TYPE_CULL,
            [['photo_id' => $photo->id, 'action' => 'cull']],
        );
        $this->service->reject($proposal, $photographer, 'No.');

        $this->expectException(\LogicException::class);
        $this->service->execute($proposal, $agent, fn () => null);
    }

    /** Only approved proposals can execute. */
    public function test_only_approved_proposal_can_execute(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();

        $draft = $this->service->createProposal(
            $project,
            $agent,
            Domain::TYPE_CULL,
            [['photo_id' => $photo->id, 'action' => 'cull']],
            null,
            null,
            Domain::STATE_DRAFT,
        );

        $this->expectException(\LogicException::class);
        $this->service->execute($draft, $agent, fn () => null);
    }

    /** Executed proposals cannot execute twice. */
    public function test_executed_proposal_cannot_execute_twice(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();

        $proposal = $this->service->createProposal(
            $project,
            $agent,
            Domain::TYPE_CULL,
            [['photo_id' => $photo->id, 'action' => 'cull']],
        );
        $this->service->approve($proposal, $photographer);

        $applied = app(ProposalApplicator::class);
        $this->service->execute($proposal, $agent, fn ($p) => $applied->apply($p));

        $photo->refresh();
        $this->assertSame(Domain::SELECTION_CULLED, $photo->selection_state);

        $this->expectException(\LogicException::class);
        $this->service->execute($proposal->fresh(), $agent, fn ($p) => $applied->apply($p));
    }

    /** Execution is scoped to the project the proposal belongs to. */
    public function test_execution_applies_only_its_own_proposal_items(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        [$otherPhotographer, $otherAgent, $otherProject] = $this->makeWorld();

        $photo = $project->photos()->first();
        $otherPhoto = $otherProject->photos()->first();

        $proposal = $this->service->createProposal(
            $project,
            $agent,
            Domain::TYPE_CULL,
            [
                ['photo_id' => $photo->id, 'action' => 'cull'],
                ['photo_id' => $otherPhoto->id, 'action' => 'cull'], // foreign photo
            ],
        );
        $this->service->approve($proposal, $photographer);

        $applied = app(ProposalApplicator::class);
        $this->service->execute($proposal, $agent, fn ($p) => $applied->apply($p));

        // The foreign photo must NOT have been touched.
        $otherPhoto->refresh();
        $this->assertSame(Domain::SELECTION_UNREVIEWED, $otherPhoto->selection_state);
    }

    /** Project isolation: a proposal from project A cannot affect project B. */
    public function test_project_isolation(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        [, , $otherProject] = $this->makeWorld();

        $photo = $project->photos()->first();
        $otherPhoto = $otherProject->photos()->first();

        $proposal = $this->service->createProposal(
            $project,
            $agent,
            Domain::TYPE_CULL,
            [['photo_id' => $photo->id, 'action' => 'cull']],
        );
        $this->service->approve($proposal, $photographer);
        $this->service->execute($proposal, $agent, app(ProposalApplicator::class)->apply(...));

        // Other project fully untouched.
        $otherPhoto->refresh();
        $this->assertSame(Domain::SELECTION_UNREVIEWED, $otherPhoto->selection_state);
        $this->assertSame(0, $otherProject->proposals()->count());
        $this->assertSame(0, QaFinding::where('project_id', $otherProject->id)->count());
    }

    /** Tool-call audit logging. */
    public function test_tool_call_audit_logging(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();

        $this->actingAs($agent)
            ->withHeaders(['User-Agent' => 'WebMCP-Harness/1.0'])
            ->getJson(route('api.webmcp.context', [$project->id]))
            ->assertOk();

        $this->assertDatabaseHas('agent_tool_calls', [
            'tool_name' => 'get_workspace_context',
            'authority' => Domain::AUTHORITY_READ,
            'project_id' => $project->id,
            'agent_id' => $agent->id,
            'result_status' => Domain::RESULT_COMPLETED,
        ]);

        $this->assertSame(1, $project->toolCalls()->count());
    }

    /** Audit is append-only (no updated_at column). */
    public function test_audit_trail_is_append_only(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();

        $this->actingAs($agent)->getJson(route('api.webmcp.context', [$project->id]))->assertOk();
        $this->actingAs($agent)->getJson(route('api.webmcp.context', [$project->id]))->assertOk();

        $call = $project->toolCalls()->first();
        $this->assertNull($call->updated_at);
        $this->assertSame(2, $project->toolCalls()->count());
    }

    /** Agent cannot exercise photographer authority (approve). */
    public function test_agent_cannot_approve_proposal(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();

        $proposal = $this->service->createProposal(
            $project,
            $agent,
            Domain::TYPE_CULL,
            [['photo_id' => $photo->id, 'action' => 'cull']],
        );

        $this->actingAs($agent)
            ->postJson(route('proposals.approve', [$project->id, $proposal->id]))
            ->assertForbidden();

        $proposal->refresh();
        $this->assertSame(Domain::STATE_PENDING_REVIEW, $proposal->status);
    }
}
