<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentConversationTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    private User $agent;

    private User $viewer;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->photographer = User::factory()->create(['name' => 'Maya']);
        $this->agent = User::factory()->agent()->create(['name' => 'Darkroom Agent']);
        $this->viewer = User::factory()->create(['name' => 'Client Viewer']);
        $this->project = Project::factory()->create(['owner_id' => $this->photographer->id]);
        $this->project->members()->syncWithoutDetaching([
            $this->photographer->id => ['role' => Domain::ROLE_OWNER],
            $this->agent->id => ['role' => Domain::ROLE_AGENT],
            $this->viewer->id => ['role' => Domain::ROLE_VIEWER],
        ]);
    }

    public function test_photographer_can_send_and_read_a_durable_idempotent_message(): void
    {
        $clientMessageId = (string) Str::uuid();
        $payload = [
            'body' => 'Which frame best matches the adopted direction?',
            'client_message_id' => $clientMessageId,
        ];

        $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.store', $this->project), $payload)
            ->assertCreated()
            ->assertJsonPath('deduplicated', false)
            ->assertJsonPath('message.author.kind', 'human')
            ->assertJsonPath('message.author.name', 'Maya')
            ->assertJsonPath('message.body', $payload['body']);

        $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.store', $this->project), $payload)
            ->assertOk()
            ->assertJsonPath('deduplicated', true);

        $this->assertDatabaseCount('agent_conversation_messages', 1);

        $this->actingAs($this->photographer)
            ->getJson(route('agent-conversation.index', $this->project))
            ->assertOk()
            ->assertJsonPath('project_id', $this->project->id)
            ->assertJsonPath('trust_boundary', 'untrusted_project_conversation')
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', $payload['body']);
    }

    public function test_authenticated_project_agent_can_read_and_reply_through_webmcp_tools(): void
    {
        $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.store', $this->project), [
                'body' => 'Explain the strongest keep.',
                'client_message_id' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $this->actingAs($this->agent)
            ->getJson(route('api.webmcp.conversation.index', $this->project))
            ->assertOk()
            ->assertJsonPath('messages.0.author.kind', 'human');

        $this->actingAs($this->agent)
            ->postJson(route('api.webmcp.conversation.reply', $this->project), [
                'body' => 'Frame 4 has the strongest expression and cleanest highlight roll-off.',
                'client_message_id' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->assertJsonPath('message.author.kind', 'agent')
            ->assertJsonPath('message.author.name', 'Darkroom Agent');

        $this->assertDatabaseHas('agent_tool_calls', [
            'project_id' => $this->project->id,
            'tool_name' => 'get_agent_conversation',
            'authority' => Domain::AUTHORITY_READ,
        ]);
        $this->assertDatabaseHas('agent_tool_calls', [
            'project_id' => $this->project->id,
            'tool_name' => 'reply_to_agent_conversation',
            'authority' => Domain::AUTHORITY_PROPOSE,
        ]);
    }

    public function test_conversation_authorization_preserves_project_and_agent_boundaries(): void
    {
        $outsider = User::factory()->agent()->create([
            'email' => 'outsider-agent@example.test',
        ]);

        $this->actingAs($this->viewer)
            ->getJson(route('agent-conversation.index', $this->project))
            ->assertOk();
        $this->actingAs($this->viewer)
            ->postJson(route('agent-conversation.store', $this->project), ['body' => 'not allowed'])
            ->assertForbidden();
        $this->actingAs($this->photographer)
            ->postJson(route('api.webmcp.conversation.reply', $this->project), ['body' => 'spoofed agent'])
            ->assertForbidden();
        $this->actingAs($outsider)
            ->getJson(route('agent-conversation.index', $this->project))
            ->assertForbidden();
        $this->actingAs($outsider)
            ->getJson(route('api.webmcp.conversation.index', $this->project))
            ->assertForbidden();

        $this->assertDatabaseCount('agent_conversation_messages', 0);
    }

    public function test_message_validation_cursor_and_workspace_bootstrap_are_bounded(): void
    {
        $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.store', $this->project), ['body' => str_repeat('x', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $first = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.store', $this->project), ['body' => 'First'])
            ->assertCreated()
            ->json('message.id');
        $this->actingAs($this->agent)
            ->postJson(route('api.webmcp.conversation.reply', $this->project), ['body' => 'Second'])
            ->assertCreated();

        $this->actingAs($this->photographer)
            ->getJson(route('agent-conversation.index', [$this->project, 'after' => $first]))
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'Second');

        $this->actingAs($this->photographer)
            ->get(route('workspace.show', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Workspace')
                ->where('permissions.can_chat', true)
                ->where('conversation.project_id', $this->project->id)
                ->has('conversation.messages', 2));
    }
}
