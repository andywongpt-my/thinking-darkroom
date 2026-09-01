<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_human_can_create_an_active_project_with_owner_membership(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'name' => '  New darkroom project  ',
            'description' => 'A project created by a photographer.',
            'owner_id' => $otherUser->id,
            'role' => Domain::ROLE_AGENT,
            'status' => 'archived',
        ]);

        $project = Project::query()->where('name', 'New darkroom project')->firstOrFail();

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.show', $project));

        $this->assertSame($user->id, $project->owner_id);
        $this->assertSame('active', $project->status);
        $this->assertSame('A project created by a photographer.', $project->description);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'owner_id' => $user->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => Domain::ROLE_OWNER,
        ]);
    }

    public function test_machine_agent_cannot_create_a_project(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)
            ->post(route('projects.store'), ['name' => 'Agent project'])
            ->assertForbidden();

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_members', 0);
    }

    public function test_machine_agent_with_invalid_input_is_forbidden_before_validation_without_writing(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)
            ->post(route('projects.store'), [
                'name' => '   ',
                'description' => str_repeat('d', 5001),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_members', 0);
    }

    public function test_invalid_project_input_redirects_with_errors_without_writing(): void
    {
        $user = User::factory()->create();

        $this->from(route('dashboard'))
            ->actingAs($user)
            ->post(route('projects.store'), [
                'name' => '   ',
                'description' => str_repeat('d', 5001),
            ])
            ->assertSessionHasErrors(['name', 'description'])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_members', 0);
    }

    public function test_guest_is_redirected_to_login_before_validation(): void
    {
        $this->post(route('projects.store'), [
            'name' => '   ',
            'description' => str_repeat('d', 5001),
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_members', 0);
    }

    public function test_project_creation_is_rate_limited_per_authenticated_actor(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($user)
                ->post(route('projects.store'), ['name' => "Project {$attempt}"])
                ->assertRedirect();
        }

        $this->actingAs($otherUser)
            ->post(route('projects.store'), ['name' => 'Other actor project'])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('projects.store'), ['name' => 'Seventh project'])
            ->assertStatus(429);

        $this->assertDatabaseCount('projects', 7);
        $this->assertDatabaseCount('project_members', 7);
    }

    public function test_unverified_human_is_not_blocked_by_the_project_creation_policy(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)
            ->post(route('projects.store'), ['name' => 'Unverified project'])
            ->assertRedirect();

        $project = Project::query()->where('name', 'Unverified project')->firstOrFail();

        $response->assertRedirect(route('workspace.show', $project));
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'owner_id' => $user->id,
        ]);
    }
}
