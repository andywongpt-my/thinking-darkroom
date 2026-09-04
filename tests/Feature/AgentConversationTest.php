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
            ->assertJsonPath('message.body', $payload['body'])
            ->assertJsonPath('message.origin', null);

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
            ->assertJsonPath('message.author.name', 'Darkroom Agent')
            ->assertJsonPath('message.origin', 'external');

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

    public function test_project_agent_can_use_webmcp_api_with_a_sanctum_bearer_token(): void
    {
        $token = $this->agent->createToken('webmcp-integration-test')->plainTextToken;

        $this->withToken($token)
            ->getJson(route('api.webmcp.conversation.index', $this->project))
            ->assertOk()
            ->assertJsonPath('project_id', $this->project->id);
    }

    public function test_webmcp_conversation_reports_pending_human_reply_signals(): void
    {
        $first = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.store', $this->project), [
                'body' => 'Please compare the two lead frames.',
            ])
            ->assertCreated();
        $firstCreatedAt = $first->json('message.created_at');

        $this->actingAs($this->agent)
            ->getJson(route('api.webmcp.conversation.index', $this->project))
            ->assertOk()
            ->assertJsonPath('awaiting_reply_since', $firstCreatedAt)
            ->assertJsonPath('unread_for_agent', 1);

        $this->actingAs($this->agent)
            ->postJson(route('api.webmcp.conversation.reply', $this->project), [
                'body' => 'I would start with the quieter expression.',
            ])
            ->assertCreated();

        $this->actingAs($this->agent)
            ->getJson(route('api.webmcp.conversation.index', $this->project))
            ->assertOk()
            ->assertJsonPath('awaiting_reply_since', null)
            ->assertJsonPath('unread_for_agent', 0);

        $second = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.store', $this->project), [
                'body' => 'Now check the alternate crop.',
            ])
            ->assertCreated();

        $this->actingAs($this->agent)
            ->getJson(route('api.webmcp.conversation.index', $this->project))
            ->assertOk()
            ->assertJsonPath('awaiting_reply_since', $second->json('message.created_at'))
            ->assertJsonPath('unread_for_agent', 1);
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

    /** U-7: the before-cursor fetches an older page with a truthful has_older. */
    public function test_before_cursor_pages_older_history_with_truthful_has_older(): void
    {
        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->actingAs($this->photographer)
                ->postJson(route('agent-conversation.store', $this->project), ['body' => "Message {$i}"])
                ->assertCreated()
                ->json('message.id');
        }

        // Newest page (default) shows only the last message… well, the last
        // two are enough to prove bounded history. Fetch older than message 3.
        $this->actingAs($this->photographer)
            ->getJson(route('agent-conversation.index', [$this->project, 'before' => $ids[2], 'limit' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'Message 2')
            ->assertJsonPath('has_older', true);

        // Exhausting history reports has_older=false.
        $this->actingAs($this->photographer)
            ->getJson(route('agent-conversation.index', [$this->project, 'before' => $ids[0], 'limit' => 5]))
            ->assertOk()
            ->assertJsonCount(0, 'messages')
            ->assertJsonPath('has_older', false);

        // Cursor validation stays bounded.
        $this->actingAs($this->photographer)
            ->getJson(route('agent-conversation.index', [$this->project, 'before' => 'nope']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('before');
    }

    public function test_agent_posting_through_the_web_route_leaves_an_audit_trail(): void
    {
        // ProjectPolicy::message allows an agent project member to post here.
        // The audit trail must match the audited WebMCP reply endpoint so an
        // agent can never write conversation history without a trace.
        $this->actingAs($this->agent)
            ->postJson(route('agent-conversation.store', $this->project), [
                'body' => 'Draft cull proposal ready for review.',
                'client_message_id' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->assertJsonPath('message.origin', 'external');

        $this->assertDatabaseHas('agent_tool_calls', [
            'project_id' => $this->project->id,
            'agent_id' => $this->agent->id,
            'tool_name' => 'agent_conversation_web_store',
            'authority' => Domain::AUTHORITY_PROPOSE,
        ]);
    }

    public function test_human_photographer_posts_leave_no_agent_tool_call_row(): void
    {
        $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.store', $this->project), [
                'body' => 'Which frame best matches the adopted direction?',
                'client_message_id' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $this->assertDatabaseCount('agent_tool_calls', 0);
    }
}
