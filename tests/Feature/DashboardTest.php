<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\AgentPresence;
use App\Models\Photo;
use App\Models\PhotographerDecision;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_projects_keep_the_public_payload_shape_and_use_aggregates(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->withPhotos(3)->create([
            'name' => 'Aggregate test project',
            'owner_id' => $user->id,
        ]);
        $project->members()->attach($user->id, ['role' => Domain::ROLE_OWNER]);

        Proposal::query()->create([
            'project_id' => $project->id,
            'created_by' => $user->id,
            'type' => Domain::TYPE_CULL,
            'status' => Domain::STATE_PENDING_REVIEW,
            'summary' => 'Pending aggregate test proposal.',
            'payload' => [],
        ]);
        Proposal::query()->create([
            'project_id' => $project->id,
            'created_by' => $user->id,
            'type' => Domain::TYPE_RETOUCH,
            'status' => Domain::STATE_APPROVED,
            'summary' => 'Approved aggregate test proposal.',
            'payload' => [],
        ]);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $page = $this->inertiaPage($response->getContent());
        $this->assertTrue($page['props']['can_create_project']);
        $projects = $page['props']['projects'] ?? [];
        $this->assertIsArray($projects);
        $this->assertCount(1, $projects);

        $payload = array_values($projects)[0];
        $this->assertIsArray($payload);
        $this->assertSame(
            ['id', 'name', 'description', 'status', 'photo_count', 'pending_proposals', 'url'],
            array_keys($payload),
        );
        $this->assertSame($project->id, $payload['id']);
        $this->assertSame($project->name, $payload['name']);
        $this->assertSame($project->status, $payload['status']);
        $this->assertSame(3, $payload['photo_count']);
        $this->assertSame(1, $payload['pending_proposals']);
        $this->assertSame(route('workspace.show', $project), $payload['url']);
    }

    public function test_database_seeder_does_not_duplicate_demo_proposals_or_decisions(): void
    {
        Storage::fake('public');

        $this->seed(DatabaseSeeder::class);
        $firstCounts = [
            'projects' => Project::query()->count(),
            'photos' => Photo::query()->count(),
            'proposals' => Proposal::query()->count(),
            'proposal_items' => ProposalItem::query()->count(),
            'decisions' => PhotographerDecision::query()->count(),
        ];

        $this->seed(DatabaseSeeder::class);
        $secondCounts = [
            'projects' => Project::query()->count(),
            'photos' => Photo::query()->count(),
            'proposals' => Proposal::query()->count(),
            'proposal_items' => ProposalItem::query()->count(),
            'decisions' => PhotographerDecision::query()->count(),
        ];

        $this->assertSame($firstCounts, $secondCounts);
        $this->assertSame(1, Proposal::query()->where('summary', 'Cull 3 technically-weak frames (motion blur / soft focus) before selects.')->count());
        $this->assertSame(1, PhotographerDecision::query()->where('decision', 'reject')->count());
    }

    public function test_dashboard_exposes_tool_inventory_and_agent_presence_for_the_darkroom_view(): void
    {
        $user = User::factory()->create();
        $agent = User::factory()->agent()->create();
        $project = Project::factory()->withPhotos(2)->create([
            'name' => 'Authority ladder project',
            'owner_id' => $user->id,
        ]);
        $project->members()->attach($user->id, ['role' => Domain::ROLE_OWNER]);
        $project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);

        AgentPresence::query()->create([
            'project_id' => $project->id,
            'user_id' => $agent->id,
            'last_seen_at' => now(),
        ]);

        $page = $this->inertiaPage(
            $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent()
        );

        $tools = $page['props']['tools'];
        $this->assertSame(22, $tools['total']);
        $this->assertSame(12, $tools['byAuthority']['READ']);
        $this->assertSame(2, $tools['byAuthority']['ANALYZE']);
        $this->assertSame(7, $tools['byAuthority']['PROPOSE']);
        $this->assertSame(1, $tools['byAuthority']['EXECUTE']);
        $this->assertSame('apply_approved_plan', $tools['dynamic']['name']);

        $agentPayload = $page['props']['agent'];
        $this->assertSame('WebMCP Agent', $agentPayload['name']);
        $this->assertTrue($agentPayload['online']);
        $this->assertNotNull($agentPayload['last_seen_at']);
        $this->assertArrayHasKey('now', $page['props']);
    }

    public function test_agent_presence_is_offline_outside_the_presence_window(): void
    {
        $user = User::factory()->create();
        $agent = User::factory()->agent()->create();
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $project->members()->attach($user->id, ['role' => Domain::ROLE_OWNER]);
        $project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);

        AgentPresence::query()->create([
            'project_id' => $project->id,
            'user_id' => $agent->id,
            'last_seen_at' => now()->subMinutes(30),
        ]);

        $page = $this->inertiaPage(
            $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent()
        );

        $this->assertFalse($page['props']['agent']['online']);
    }

    public function test_dashboard_uses_the_shared_ninety_second_presence_ttl(): void
    {
        $user = User::factory()->create();
        $agent = User::factory()->agent()->create(['email' => 'ttl-agent@example.test']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $project->members()->attach($user->id, ['role' => Domain::ROLE_OWNER]);
        $project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);
        $heartbeatAt = CarbonImmutable::parse('2026-08-30 12:00:00');

        CarbonImmutable::setTestNow($heartbeatAt);
        try {
            AgentPresence::query()->create([
                'project_id' => $project->id,
                'user_id' => $agent->id,
                'last_seen_at' => $heartbeatAt,
            ]);

            CarbonImmutable::setTestNow($heartbeatAt->addSeconds(91));
            $page = $this->inertiaPage(
                $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent(),
            );

            $this->assertFalse($page['props']['agent']['online']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_dashboard_uses_the_most_recent_presence_across_all_agent_members(): void
    {
        $user = User::factory()->create();
        $firstAgent = User::factory()->agent()->create(['email' => 'first-agent@example.test']);
        $secondAgent = User::factory()->agent()->create(['email' => 'second-agent@example.test']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $project->members()->attach($user->id, ['role' => Domain::ROLE_OWNER]);
        $project->members()->attach($firstAgent->id, ['role' => Domain::ROLE_AGENT]);
        $project->members()->attach($secondAgent->id, ['role' => Domain::ROLE_AGENT]);
        $checkedAt = CarbonImmutable::parse('2026-08-30 12:00:00');

        CarbonImmutable::setTestNow($checkedAt);
        try {
            AgentPresence::query()->create([
                'project_id' => $project->id,
                'user_id' => $firstAgent->id,
                'last_seen_at' => $checkedAt->subSeconds(361),
            ]);
            AgentPresence::query()->create([
                'project_id' => $project->id,
                'user_id' => $secondAgent->id,
                'last_seen_at' => $checkedAt,
            ]);

            $page = $this->inertiaPage(
                $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent(),
            );

            $this->assertTrue($page['props']['agent']['online']);
            $this->assertSame($checkedAt->toIso8601String(), $page['props']['agent']['last_seen_at']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_machine_agent_dashboard_does_not_expose_project_creation(): void
    {
        $agent = User::factory()->agent()->create();

        $page = $this->inertiaPage(
            $this->actingAs($agent)->get(route('dashboard'))->assertOk()->getContent()
        );

        $this->assertFalse($page['props']['can_create_project']);
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
}
