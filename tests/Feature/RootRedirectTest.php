<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RootRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_guests_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }

    public function test_root_redirects_authenticated_users_to_dashboard_not_newest_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $project->members()->attach($user->id, ['role' => 'owner']);

        $response = $this->actingAs($user)->get('/');

        // The dashboard is the app home (2026-09-03 fix): clicking the logo
        // must never bounce to the newest project.
        $response->assertRedirect(route('dashboard'));
    }
}
