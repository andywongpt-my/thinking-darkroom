<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_owner_can_invite_an_existing_agent_to_one_project(): void
    {
        $owner = User::factory()->create();
        $agent = User::factory()->agent()->create([
            'email' => 'agent-one@example.test',
        ]);
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $otherProject = Project::factory()->create(['owner_id' => $owner->id]);
        $project->members()->attach($owner->id, ['role' => Domain::ROLE_OWNER]);
        $otherProject->members()->attach($owner->id, ['role' => Domain::ROLE_OWNER]);

        $response = $this->actingAs($owner)->post(route('projects.agents.store', $project), [
            'email' => $agent->email,
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.success', 'Agent invited to the project.')
            ->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $agent->id,
            'role' => Domain::ROLE_AGENT,
        ]);
        $this->assertDatabaseMissing('project_members', [
            'project_id' => $otherProject->id,
            'user_id' => $agent->id,
        ]);
    }

    public function test_reinviting_a_correctly_attached_agent_is_idempotent(): void
    {
        [$owner, $agent, $project] = $this->ownerProjectAndAgent();
        $project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);

        $response = $this->actingAs($owner)->post(route('projects.agents.store', $project), [
            'email' => $agent->email,
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.success', 'Agent is already a member of this project.')
            ->assertRedirect(route('dashboard'));
        $this->assertSame(1, $project->members()->whereKey($agent->id)->count());
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $agent->id,
            'role' => Domain::ROLE_AGENT,
        ]);
    }

    public function test_unknown_email_is_rejected_without_creating_a_membership(): void
    {
        [$owner, $agent, $project] = $this->ownerProjectAndAgent();

        $response = $this->actingAs($owner)->post(route('projects.agents.store', $project), [
            'email' => 'missing-agent@example.test',
        ]);

        $response
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $agent->id,
        ]);
    }

    public function test_non_agent_account_is_rejected_without_changing_an_existing_member_role(): void
    {
        [$owner, $agent, $project] = $this->ownerProjectAndAgent();
        $person = User::factory()->create(['email' => 'photographer@example.test']);
        $project->members()->attach($person->id, ['role' => Domain::ROLE_PHOTOGRAPHER]);

        $response = $this->actingAs($owner)->post(route('projects.agents.store', $project), [
            'email' => $person->email,
        ]);

        $response
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $person->id,
            'role' => Domain::ROLE_PHOTOGRAPHER,
        ]);
        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $agent->id,
        ]);
    }

    public function test_attached_agent_with_a_non_agent_role_is_rejected_without_a_role_change(): void
    {
        [$owner, $agent, $project] = $this->ownerProjectAndAgent();
        $project->members()->attach($agent->id, ['role' => Domain::ROLE_VIEWER]);

        $response = $this->actingAs($owner)->post(route('projects.agents.store', $project), [
            'email' => $agent->email,
        ]);

        $response
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $agent->id,
            'role' => Domain::ROLE_VIEWER,
        ]);
    }

    public function test_agent_account_cannot_invite_an_agent(): void
    {
        [$owner, $targetAgent, $project] = $this->ownerProjectAndAgent();
        $caller = User::factory()->agent()->create(['email' => 'caller-agent@example.test']);
        $project->members()->attach($caller->id, ['role' => Domain::ROLE_AGENT]);

        $this->actingAs($caller)
            ->post(route('projects.agents.store', $project), ['email' => $targetAgent->email])
            ->assertForbidden();
    }

    public function test_non_owner_member_cannot_invite_an_agent(): void
    {
        [$owner, $targetAgent, $project] = $this->ownerProjectAndAgent();
        $member = User::factory()->create(['email' => 'member@example.test']);
        $project->members()->attach($member->id, ['role' => Domain::ROLE_PHOTOGRAPHER]);

        $this->actingAs($member)
            ->post(route('projects.agents.store', $project), ['email' => $targetAgent->email])
            ->assertForbidden();
        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $targetAgent->id,
        ]);
    }

    public function test_outsider_cannot_invite_an_agent_into_a_project(): void
    {
        [$owner, $targetAgent, $project] = $this->ownerProjectAndAgent();
        $outsider = User::factory()->create(['email' => 'outsider@example.test']);

        $this->actingAs($outsider)
            ->post(route('projects.agents.store', $project), ['email' => $targetAgent->email])
            ->assertForbidden();
        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $targetAgent->id,
        ]);
    }

    public function test_owner_of_another_project_cannot_invite_into_this_project(): void
    {
        [$targetOwner, $targetAgent, $targetProject] = $this->ownerProjectAndAgent();
        $otherOwner = User::factory()->create(['email' => 'other-owner@example.test']);
        $otherProject = Project::factory()->create(['owner_id' => $otherOwner->id]);
        $otherProject->members()->attach($otherOwner->id, ['role' => Domain::ROLE_OWNER]);

        $this->actingAs($otherOwner)
            ->post(route('projects.agents.store', $targetProject), ['email' => $targetAgent->email])
            ->assertForbidden();
        $this->assertDatabaseMissing('project_members', [
            'project_id' => $targetProject->id,
            'user_id' => $targetAgent->id,
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $otherProject->id,
            'user_id' => $otherOwner->id,
            'role' => Domain::ROLE_OWNER,
        ]);
        $this->assertDatabaseHas('projects', ['id' => $targetProject->id, 'owner_id' => $targetOwner->id]);
    }

    /** @return array{0: User, 1: User, 2: Project} */
    private function ownerProjectAndAgent(): array
    {
        $owner = User::factory()->create();
        $agent = User::factory()->agent()->create(['email' => 'target-agent@example.test']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $project->members()->attach($owner->id, ['role' => Domain::ROLE_OWNER]);

        return [$owner, $agent, $project];
    }
}
