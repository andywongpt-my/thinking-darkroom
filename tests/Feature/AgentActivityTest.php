<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\AgentToolCall;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentActivityTest extends TestCase
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
        $this->agent = User::factory()->agent()->create(['name' => 'Codex']);
        $this->viewer = User::factory()->create(['name' => 'Viewer']);
        $this->project = Project::factory()->create(['owner_id' => $this->photographer->id]);
        $this->project->members()->sync([
            $this->photographer->id => ['role' => Domain::ROLE_OWNER],
            $this->agent->id => ['role' => Domain::ROLE_AGENT],
            $this->viewer->id => ['role' => Domain::ROLE_VIEWER],
        ]);
    }

    public function test_project_activity_is_newest_first_and_maps_untrusted_ledger_fields(): void
    {
        $first = $this->createActivity('inspect_photo', ['photo_id' => 1], ['observed' => true]);
        $second = $this->createActivity('propose_cull', ['count' => 2], ['proposal_id' => 9]);
        $third = $this->createActivity('reply_to_agent_conversation', ['body' => '<untrusted>'], ['message_id' => 12]);

        $this->actingAs($this->photographer)
            ->getJson(route('agent-activity.index', $this->project))
            ->assertOk()
            ->assertJsonPath('project_id', $this->project->id)
            ->assertJsonPath('latest_id', $third->id)
            ->assertJsonPath('has_older', false)
            ->assertJsonCount(3, 'activity')
            ->assertJsonPath('activity.0.id', $third->id)
            ->assertJsonPath('activity.0.agent.name', 'Codex')
            ->assertJsonPath('activity.0.agent.is_agent', true)
            ->assertJsonPath('activity.0.tool_name', 'reply_to_agent_conversation')
            ->assertJsonPath('activity.0.authority', Domain::AUTHORITY_PROPOSE)
            ->assertJsonPath('activity.0.result_status', Domain::RESULT_COMPLETED)
            ->assertJsonPath('activity.0.summary_in.body', '<untrusted>')
            ->assertJsonPath('activity.0.summary_out.message_id', 12)
            ->assertJsonPath('activity.0.duration_ms', 42)
            ->assertJsonPath('activity.0.created_at', $third->created_at?->toISOString());

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_activity_supports_before_cursor_pagination(): void
    {
        $first = $this->createActivity('first_tool', [], []);
        $second = $this->createActivity('second_tool', [], []);
        $third = $this->createActivity('third_tool', [], []);

        $this->actingAs($this->photographer)
            ->getJson(route('agent-activity.index', [
                'project' => $this->project,
                'before' => $third->id,
                'limit' => 1,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'activity')
            ->assertJsonPath('activity.0.id', $second->id)
            ->assertJsonPath('has_older', true)
            ->assertJsonPath('latest_id', $third->id);

        $this->actingAs($this->photographer)
            ->getJson(route('agent-activity.index', [
                'project' => $this->project,
                'after' => $first->id,
                'limit' => 1,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'activity')
            ->assertJsonPath('activity.0.id', $second->id)
            ->assertJsonPath('latest_id', $second->id)
            ->assertJsonPath('has_older', false);

        $this->actingAs($this->photographer)
            ->getJson(route('agent-activity.index', [
                'project' => $this->project,
                'after' => $second->id,
                'limit' => 1,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'activity')
            ->assertJsonPath('activity.0.id', $third->id)
            ->assertJsonPath('latest_id', $third->id)
            ->assertJsonPath('has_older', false);
    }

    public function test_activity_only_allows_project_viewers(): void
    {
        $this->createActivity('inspect_photo', [], []);

        $this->actingAs($this->viewer)
            ->getJson(route('agent-activity.index', $this->project))
            ->assertOk();

        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->getJson(route('agent-activity.index', $this->project))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     */
    private function createActivity(string $toolName, array $input, array $output): AgentToolCall
    {
        return AgentToolCall::query()->create([
            'project_id' => $this->project->id,
            'agent_id' => $this->agent->id,
            'tool_name' => $toolName,
            'authority' => Domain::AUTHORITY_PROPOSE,
            'http_method' => 'POST',
            'path' => 'api/projects/'.$this->project->id.'/activity',
            'result_status' => Domain::RESULT_COMPLETED,
            'input' => $input,
            'output_summary' => $output,
            'duration_ms' => 42,
            'ip' => '127.0.0.1',
            'user_agent' => 'activity-test/'.Str::random(4),
        ]);
    }
}
