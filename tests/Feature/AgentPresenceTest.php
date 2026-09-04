<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentPresenceTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->photographer = User::factory()->create(['name' => 'Maya']);
        $this->project = Project::factory()->create(['owner_id' => $this->photographer->id]);
        $this->project->members()->sync([
            $this->photographer->id => ['role' => Domain::ROLE_OWNER],
        ]);
    }

    public function test_presence_read_is_offline_and_empty_before_any_agent_heartbeat(): void
    {
        $response = $this->actingAs($this->photographer)
            ->getJson(route('api.presence.show', [$this->project->id]));

        $response->assertOk()
            ->assertJsonStructure([
                'project_id',
                'online',
                'agents' => [],
                'checked_at',
            ]);

        $this->assertSame($this->project->id, $response->json('project_id'));
        $this->assertFalse($response->json('online'));
        $this->assertSame([], $response->json('agents'));
        $this->assertIsString($response->json('checked_at'));
    }

    public function test_eligible_project_agent_is_listed_offline_before_their_first_heartbeat(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Darkroom Agent']);
        $this->project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);

        $this->actingAs($this->photographer)
            ->getJson(route('api.presence.show', [$this->project->id]))
            ->assertOk()
            ->assertJsonPath('online', false)
            ->assertJsonPath('agents.0.id', $agent->id)
            ->assertJsonPath('agents.0.name', 'Darkroom Agent')
            ->assertJsonPath('agents.0.status', 'offline')
            ->assertJsonPath('agents.0.last_seen_at', null)
            ->assertJsonMissingPath('agents.0.email');
    }

    public function test_eligible_agent_heartbeat_returns_online_presence_to_project_members_without_email(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Darkroom Agent']);
        $this->project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);

        $heartbeat = $this->actingAs($agent)
            ->postJson(route('api.presence.heartbeat', [$this->project->id]));

        $heartbeat->assertOk()
            ->assertJsonPath('project_id', $this->project->id)
            ->assertJsonPath('online', true)
            ->assertJsonPath('agents.0.id', $agent->id)
            ->assertJsonPath('agents.0.name', 'Darkroom Agent')
            ->assertJsonPath('agents.0.status', 'online')
            ->assertJsonMissingPath('agents.0.email');

        $this->assertDatabaseHas('agent_presences', [
            'project_id' => $this->project->id,
            'user_id' => $agent->id,
        ]);

        $this->actingAs($this->photographer)
            ->getJson(route('api.presence.show', [$this->project->id]))
            ->assertOk()
            ->assertJsonPath('online', true)
            ->assertJsonPath('agents.0.name', 'Darkroom Agent')
            ->assertJsonMissingPath('agents.0.email');

        // Heartbeats are operational liveness and must not create creative-tool activity.
        $this->assertDatabaseCount('agent_tool_calls', 0);
    }

    public function test_repeated_agent_heartbeats_leave_one_presence_row(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Darkroom Agent']);
        $this->project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);

        $this->actingAs($agent)
            ->postJson(route('api.presence.heartbeat', [$this->project->id]))
            ->assertOk();
        $this->actingAs($agent)
            ->postJson(route('api.presence.heartbeat', [$this->project->id]))
            ->assertOk();

        $this->assertDatabaseCount('agent_presences', 1);
        $this->assertDatabaseHas('agent_presences', [
            'project_id' => $this->project->id,
            'user_id' => $agent->id,
        ]);
    }

    public function test_presence_expires_after_ninety_seconds_using_server_time(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Darkroom Agent']);
        $this->project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);
        $heartbeatAt = CarbonImmutable::parse('2026-08-30 12:00:00');

        CarbonImmutable::setTestNow($heartbeatAt);
        try {
            $this->actingAs($agent)
                ->postJson(route('api.presence.heartbeat', [$this->project->id]))
                ->assertOk()
                ->assertJsonPath('online', true);

            CarbonImmutable::setTestNow($heartbeatAt->addSeconds(91));
            $this->actingAs($this->photographer)
                ->getJson(route('api.presence.show', [$this->project->id]))
                ->assertOk()
                ->assertJsonPath('online', false)
                ->assertJsonPath('agents.0.status', 'offline')
                ->assertJsonPath('agents.0.last_seen_at', $heartbeatAt->toISOString());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_humans_and_viewers_can_read_but_cannot_heartbeat(): void
    {
        $viewer = User::factory()->create(['name' => 'Viewer']);
        $roleAgentHuman = User::factory()->create(['name' => 'Human Agent Role', 'is_agent' => false]);
        $flaggedPhotographer = User::factory()->agent()->create(['name' => 'Flagged Photographer']);
        $this->project->members()->attach([
            $viewer->id => ['role' => Domain::ROLE_VIEWER],
            $roleAgentHuman->id => ['role' => Domain::ROLE_AGENT],
            $flaggedPhotographer->id => ['role' => Domain::ROLE_PHOTOGRAPHER],
        ]);

        foreach ([$this->photographer, $viewer, $roleAgentHuman, $flaggedPhotographer] as $member) {
            $this->actingAs($member)
                ->getJson(route('api.presence.show', [$this->project->id]))
                ->assertOk();

            $this->actingAs($member)
                ->postJson(route('api.presence.heartbeat', [$this->project->id]))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('agent_presences', 0);
    }

    public function test_outsider_cannot_read_or_heartbeat_project_presence(): void
    {
        $outsider = User::factory()->agent()->create(['name' => 'Outsider Agent']);

        $this->actingAs($outsider)
            ->getJson(route('api.presence.show', [$this->project->id]))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->postJson(route('api.presence.heartbeat', [$this->project->id]))
            ->assertForbidden();

        $this->assertDatabaseCount('agent_presences', 0);
    }

    public function test_workspace_page_includes_server_rendered_presence_payload(): void
    {
        $response = $this->actingAs($this->photographer)
            ->get(route('workspace.show', [$this->project->id]))
            ->assertOk();

        $page = $this->inertiaPage($response->getContent());
        $this->assertSame([
            'project_id' => $this->project->id,
            'online' => false,
            'agents' => [],
        ], array_intersect_key($page['props']['presence'], array_flip(['project_id', 'online', 'agents'])));
        $this->assertArrayHasKey('checked_at', $page['props']['presence']);
    }

    /**
     * @return array{props: array<string, mixed>}
     */
    private function inertiaPage(string $content): array
    {
        preg_match('/data-page="([^\"]+)"/', $content, $matches);
        $this->assertNotEmpty($matches, 'Inertia data-page payload must exist');

        $page = json_decode(htmlspecialchars_decode($matches[1], ENT_QUOTES), true);
        $this->assertIsArray($page);
        $this->assertArrayHasKey('props', $page);
        $this->assertIsArray($page['props']);

        return $page;
    }

    /* ------------------------------------------------------------------ */
    /*  Tool-call presence touch (Option A) */
    /* ------------------------------------------------------------------ */

    public function test_agent_tool_call_touches_presence_and_reads_back_online(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Darkroom Agent']);
        $this->project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);

        $touchedAt = CarbonImmutable::parse('2026-09-04 10:00:00');
        CarbonImmutable::setTestNow($touchedAt);
        try {
            // Any WebMCP tool endpoint refreshes presence as a side effect.
            $this->actingAs($agent)
                ->getJson(route('api.webmcp.context', [$this->project->id]))
                ->assertOk();

            $this->assertDatabaseHas('agent_presences', [
                'project_id' => $this->project->id,
                'user_id' => $agent->id,
            ]);

            // The photographer's dashboard/strip sees the agent online.
            $this->actingAs($this->photographer)
                ->getJson(route('api.presence.show', [$this->project->id]))
                ->assertOk()
                ->assertJsonPath('online', true)
                ->assertJsonPath('agents.0.id', $agent->id)
                ->assertJsonPath('agents.0.status', 'online')
                ->assertJsonPath('agents.0.last_seen_at', $touchedAt->toISOString());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_external_agent_bearer_token_tool_call_touches_presence(): void
    {
        // The competition-harness path: a standalone agent (e.g. Codex) calls
        // the WebMCP API with a personal access token, no browser session.
        $agent = User::factory()->agent()->create(['name' => 'Darkroom Agent']);
        $this->project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);
        $token = $agent->createToken('agent-cli')->plainTextToken;

        $touchedAt = CarbonImmutable::parse('2026-09-04 11:00:00');
        CarbonImmutable::setTestNow($touchedAt);
        try {
            $this->withToken($token)
                ->getJson(route('api.webmcp.context', [$this->project->id]))
                ->assertOk();

            $this->assertDatabaseHas('agent_presences', [
                'project_id' => $this->project->id,
                'user_id' => $agent->id,
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_agent_tool_call_touch_does_not_create_activity_ledger_rows(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Darkroom Agent']);
        $this->project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);

        $this->actingAs($agent)
            ->getJson(route('api.webmcp.context', [$this->project->id]))
            ->assertOk();

        // Liveness is operational state, exactly like the explicit heartbeat:
        // it must never surface as creative-tool activity.
        $this->assertDatabaseCount('agent_tool_calls', 1);
    }

    public function test_repeated_tool_calls_leave_one_presence_row_per_agent(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Darkroom Agent']);
        $this->project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);

        $this->actingAs($agent)
            ->getJson(route('api.webmcp.context', [$this->project->id]))
            ->assertOk();
        $this->actingAs($agent)
            ->getJson(route('api.webmcp.decisions.index', [$this->project->id]))
            ->assertOk();

        $this->assertDatabaseCount('agent_presences', 1);
    }

    public function test_tool_calls_by_humans_or_non_member_agents_do_not_touch_presence(): void
    {
        $viewer = User::factory()->create(['name' => 'Viewer']);
        $roleAgentHuman = User::factory()->create(['name' => 'Human Agent Role', 'is_agent' => false]);
        $outsiderAgent = User::factory()->agent()->create(['name' => 'Outsider Agent']);
        $this->project->members()->attach([
            $viewer->id => ['role' => Domain::ROLE_VIEWER],
            $roleAgentHuman->id => ['role' => Domain::ROLE_AGENT],
        ]);

        foreach ([$this->photographer, $viewer, $roleAgentHuman] as $member) {
            $this->actingAs($member)
                ->getJson(route('api.webmcp.context', [$this->project->id]))
                ->assertOk();
        }

        // Outsider agent: not a member of this project — tool call must 403
        // AND must not write a presence row.
        $this->actingAs($outsiderAgent)
            ->getJson(route('api.webmcp.context', [$this->project->id]))
            ->assertForbidden();

        $this->assertDatabaseCount('agent_presences', 0);
    }
}
