<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\AgentConversationMessage;
use App\Models\AgentToolCall;
use App\Models\Project;
use App\Models\User;
use App\Services\AgentTurnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class AgentConversationTurnTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    private User $agent;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->photographer = User::factory()->create(['name' => 'Maya']);
        $this->agent = User::factory()->agent()->create(['name' => 'Darkroom Agent']);
        $this->project = Project::factory()->withPhotos(2)->create([
            'owner_id' => $this->photographer->id,
        ]);
        $this->project->members()->sync([
            $this->photographer->id => ['role' => Domain::ROLE_OWNER],
            $this->agent->id => ['role' => Domain::ROLE_AGENT],
        ]);
    }

    public function test_turn_requires_explicit_offline_assistant_opt_in(): void
    {
        $triggerId = $this->createHumanTrigger();

        $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), [
                'trigger_id' => $triggerId,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.client_opt_in.0',
                'The built-in offline assistant is opt-in; pass client_opt_in=true',
            );

        $this->assertDatabaseCount('agent_conversation_messages', 1);
    }

    public function test_photographer_turn_creates_a_deterministic_agent_reply_and_audit_row(): void
    {
        $triggerId = $this->createHumanTrigger();

        $response = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), [
                'client_opt_in' => true,
                'trigger_id' => $triggerId,
            ]);

        $response->assertOk()
            ->assertJsonPath('message.author.id', $this->agent->id)
            ->assertJsonPath('message.author.kind', AgentConversationMessage::AUTHOR_AGENT)
            ->assertJsonPath('message.author.name', 'Darkroom Agent')
            ->assertJsonPath('message.client_message_id', $this->expectedTurnKey($triggerId))
            ->assertJsonPath('message.origin', 'agent_turn')
            ->assertJsonMissingPath('skipped');

        $this->assertStringContainsString('I reviewed the project: 2 photos', $response->json('message.body'));
        $this->assertStringContainsString('the photographer decides', $response->json('message.body'));

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $response->json('message.client_message_id'),
            'The turn idempotency key must be a valid UUIDv5 (Postgres uuid column).'
        );

        $audit = AgentToolCall::query()
            ->where('project_id', $this->project->id)
            ->where('tool_name', 'agent_turn')
            ->firstOrFail();

        $this->assertSame($this->agent->id, $audit->agent_id);
        $this->assertSame(Domain::AUTHORITY_ANALYZE, $audit->authority);
        $this->assertSame(['trigger_id' => $triggerId], $audit->input);
        $this->assertSame($response->json('message.id'), $audit->output_summary['reply_id']);
        $this->assertSame(2, $audit->output_summary['photos']);
    }

    public function test_replaying_the_same_trigger_returns_the_same_reply_without_a_duplicate(): void
    {
        $triggerId = $this->createHumanTrigger();
        $payload = [
            'trigger_id' => $triggerId,
            'client_opt_in' => true,
        ];

        $first = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), $payload)
            ->assertOk();
        $firstReplyId = $first->json('message.id');

        $second = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), $payload)
            ->assertOk();

        $this->assertSame($firstReplyId, $second->json('message.id'));
        $this->assertSame(
            1,
            AgentConversationMessage::query()
                ->where('project_id', $this->project->id)
                ->where('client_message_id', $this->expectedTurnKey($triggerId))
                ->count(),
        );
    }

    public function test_non_human_triggers_are_skipped_without_a_self_reply(): void
    {
        $trigger = AgentConversationMessage::query()->create([
            'project_id' => $this->project->id,
            'user_id' => $this->agent->id,
            'author_kind' => AgentConversationMessage::AUTHOR_AGENT,
            'body' => 'Agent-authored trigger',
            'client_message_id' => (string) Str::uuid(),
        ]);

        $result = app(AgentTurnService::class)->run($this->project, $trigger);

        $this->assertSame([
            'message' => null,
            'skipped' => 'non_human_trigger',
        ], $result);
        $this->assertDatabaseCount('agent_conversation_messages', 1);
    }

    public function test_turn_is_skipped_when_no_agent_member_is_attached(): void
    {
        $project = Project::factory()->withPhotos(1)->create([
            'owner_id' => $this->photographer->id,
        ]);
        $project->members()->sync([
            $this->photographer->id => ['role' => Domain::ROLE_OWNER],
        ]);
        $trigger = AgentConversationMessage::query()->create([
            'project_id' => $project->id,
            'user_id' => $this->photographer->id,
            'author_kind' => AgentConversationMessage::AUTHOR_HUMAN,
            'body' => 'Is this set ready for review?',
            'client_message_id' => (string) Str::uuid(),
        ]);

        $response = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $project), [
                'client_opt_in' => true,
                'trigger_id' => $trigger->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', null)
            ->assertJsonPath('skipped', 'no_agent_member');
    }

    public function test_agent_accounts_cannot_initiate_a_turn_for_a_photographer_message(): void
    {
        $triggerId = $this->createHumanTrigger();

        $this->actingAs($this->agent)
            ->postJson(route('agent-conversation.turn', $this->project), [
                'client_opt_in' => true,
                'trigger_id' => $triggerId,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('agent_conversation_messages', [
            'client_message_id' => $this->expectedTurnKey($triggerId),
        ]);
    }

    /**
     * Mirrors the service's deterministic UUIDv5 idempotency key so tests can
     * assert the exact value without knowing the namespace constant.
     */
    private function expectedTurnKey(int $triggerId): string
    {
        return Uuid::uuid5(
            '0f6e2a8e-9c3d-4f7a-9e1b-2c5d4e6f7a8b',
            'agent-turn:'.$this->project->id.':'.$triggerId,
        )->toString();
    }

    private function createHumanTrigger(): int
    {
        return $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.store', $this->project), [
                'body' => 'Please review this project.',
                'client_message_id' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->json('message.id');
    }
}
