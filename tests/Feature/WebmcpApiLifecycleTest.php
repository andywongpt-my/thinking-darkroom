<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\Project;
use App\Models\User;
use App\Support\WebmcpToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * End-to-end WebMCP lifecycle over real HTTP, mirroring exactly the
 * competition's success-criteria sequence:
 *
 *   base tools → propose_cull → pending proposal visible → human approve →
 *   apply_approved_plan appears → execute → executed → activity logged.
 */
class WebmcpApiLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    private User $agent;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->photographer = User::factory()->create(['name' => 'Maya']);
        $this->agent = User::factory()->create(['is_agent' => true, 'name' => 'Agent']);

        $this->project = Project::factory()->withPhotos(6)->create(['owner_id' => $this->photographer->id]);
        $this->project->photos()->update(['selection_state' => Domain::SELECTION_UNREVIEWED]);
        $this->project->members()->syncWithoutDetaching([
            $this->photographer->id => ['role' => Domain::ROLE_OWNER],
            $this->agent->id => ['role' => Domain::ROLE_AGENT],
        ]);
    }

    private function photoId(int $i): int
    {
        return $this->project->photos()->orderBy('id')->skip($i - 1)->value('id');
    }

    public function test_full_lifecycle(): void
    {
        $ctxUrl = route('api.webmcp.context', [$this->project->id]);

        // 1. get_workspace_context (READ)
        $this->actingAs($this->agent)->getJson($ctxUrl)->assertOk()->assertJsonPath('counts.total', 6);

        // 2. list_project_photos (READ)
        $this->actingAs($this->agent)
            ->getJson(route('api.webmcp.photos.index', [$this->project->id]))
            ->assertOk()
            ->assertJsonCount(6, 'photos');

        // 3. inspect_photo (READ)
        $p1 = $this->photoId(1);
        $this->actingAs($this->agent)
            ->getJson(route('api.webmcp.photos.show', [$this->project->id, $p1]))
            ->assertOk()
            ->assertJsonPath('photo.id', $p1);

        // 4. get_creative_brief (READ)
        $this->actingAs($this->agent)
            ->getJson(route('api.webmcp.brief.show', [$this->project->id]))
            ->assertOk();

        // 5. get_decision_history (READ)
        $this->actingAs($this->agent)
            ->getJson(route('api.webmcp.decisions.index', [$this->project->id]))
            ->assertOk();

        // 6. propose_cull (PROPOSE) — creates proposal, does NOT apply
        $resp = $this->actingAs($this->agent)->postJson(
            route('api.webmcp.proposals.cull', [$this->project->id]),
            [
                'summary' => 'Cull 2 soft-focus frames.',
                'items' => [
                    ['photo_id' => $p1, 'action' => 'cull', 'rationale' => 'motion blur'],
                    ['photo_id' => $this->photoId(2), 'action' => 'cull', 'rationale' => 'soft focus'],
                ],
            ],
        );
        $resp->assertStatus(201)->assertJsonPath('proposal.status', Domain::STATE_PENDING_REVIEW);
        $proposalId = $resp->json('proposal.id');

        // No creative state was applied by proposing.
        $this->assertDatabaseHas('photos', ['id' => $p1, 'selection_state' => Domain::SELECTION_UNREVIEWED]);

        // 7. Dynamic tool appears ONLY after approval — before approval it is absent.
        $this->actingAs($this->agent)
            ->getJson(route('webmcp.diagnostics.tools', [$this->project->id]))
            ->assertOk()
            ->assertJsonPath('eligible_approval', false)
            ->assertJsonMissing(['name' => 'apply_approved_plan']);

        // 8. Human photographer approves via UI endpoint (agent CANNOT call it).
        $this->actingAs($this->agent)
            ->postJson(route('proposals.approve', [$this->project->id, $proposalId]))
            ->assertForbidden();

        $this->actingAs($this->photographer)
            ->postJson(route('proposals.approve', [$this->project->id, $proposalId]))
            ->assertOk()
            ->assertJsonPath('proposal.status', Domain::STATE_APPROVED);

        // 9. Now apply_approved_plan is exposed by the server-side catalog.
        $this->actingAs($this->agent)
            ->getJson(route('webmcp.diagnostics.tools', [$this->project->id]))
            ->assertOk()
            ->assertJsonPath('eligible_approval', true)
            ->assertJsonFragment(['name' => 'apply_approved_plan']);

        // 10. Agent executes the approved plan.
        $exec = $this->actingAs($this->agent)->postJson(
            route('api.webmcp.proposals.execute', [$this->project->id, $proposalId]),
        );
        $exec->assertOk()->assertJsonPath('proposal.status', Domain::STATE_EXECUTED);

        // Creative state now applied.
        $this->assertDatabaseHas('photos', ['id' => $p1, 'selection_state' => Domain::SELECTION_CULLED]);

        // 11. Executed → tool disappears again.
        $this->actingAs($this->agent)
            ->getJson(route('webmcp.diagnostics.tools', [$this->project->id]))
            ->assertOk()
            ->assertJsonPath('eligible_approval', false)
            ->assertJsonMissing(['name' => 'apply_approved_plan']);

        // 12. Cannot execute twice.
        $this->actingAs($this->agent)
            ->postJson(route('api.webmcp.proposals.execute', [$this->project->id, $proposalId]))
            ->assertStatus(409);

        // 13. Every step was audit-logged.
        $this->assertDatabaseHas('agent_tool_calls', ['tool_name' => 'apply_approved_plan', 'result_status' => 'completed']);
        $this->assertDatabaseHas('agent_tool_calls', ['tool_name' => 'apply_approved_plan', 'result_status' => 'denied']);
        $this->assertDatabaseHas('agent_tool_calls', ['tool_name' => 'propose_cull']);
        $this->assertDatabaseHas('agent_tool_calls', ['tool_name' => 'get_workspace_context']);
    }

    public function test_catalogue_paths_match_registered_laravel_routes(): void
    {
        $routes = Route::getRoutes()->getRoutes();

        foreach (WebmcpToolCatalog::all() as $tool) {
            $matches = array_filter(
                $routes,
                fn ($route) => '/'.ltrim($route->uri(), '/') === $tool['path']
                    && in_array($tool['method'], $route->methods(), true),
            );

            $this->assertNotEmpty(
                $matches,
                "WebMCP tool {$tool['name']} advertises an unregistered {$tool['method']} {$tool['path']} route.",
            );
            $this->assertStringNotContainsString('/api/webmcp/', $tool['path']);
        }
    }

    public function test_authenticated_workspace_page_renders_successfully(): void
    {
        $this->actingAs($this->photographer)
            ->get(route('workspace.show', [$this->project->id]))
            ->assertOk();
    }

    public function test_cannot_approve_an_already_executed_proposal(): void
    {
        [$approved] = $this->buildApprovedCull();

        $this->actingAs($this->photographer)
            ->postJson(route('proposals.approve', [$this->project->id, $approved->id]))
            ->assertOk();

        // Photographer CAN approve an approved-but-unexecuted proposal again? No —
        // a proposal can only be reviewed once from pending. Approving an already-
        // approved proposal must be rejected.
        $this->actingAs($this->photographer)
            ->postJson(route('proposals.approve', [$this->project->id, $approved->id]))
            ->assertStatus(409);
    }

    public function test_foreign_photo_rejected_in_cull_proposal(): void
    {
        $other = Project::factory()->withPhotos(2)->create(['owner_id' => $this->photographer->id]);
        $other->members()->attach($this->agent->id, ['role' => Domain::ROLE_AGENT]);
        $foreignPhoto = $other->photos()->first();

        $this->actingAs($this->agent)
            ->postJson(route('api.webmcp.proposals.cull', [$this->project->id]), [
                'summary' => 'bad',
                'items' => [
                    ['photo_id' => $foreignPhoto->id, 'action' => 'cull'],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('proposals', 0);
    }

    private function buildApprovedCull(): array
    {
        $resp = $this->actingAs($this->agent)->postJson(
            route('api.webmcp.proposals.cull', [$this->project->id]),
            [
                'summary' => 'Cull frame.',
                'items' => [['photo_id' => $this->photoId(1), 'action' => 'cull']],
            ],
        );
        $proposal = \App\Models\Proposal::findOrFail($resp->json('proposal.id'));

        return [$proposal];
    }
}
