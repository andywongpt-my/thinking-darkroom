<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebmcpRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_expensive_project_analysis_is_rate_limited_per_agent_and_project(): void
    {
        $agent = User::factory()->agent()->create();
        $project = Project::factory()->create(['owner_id' => User::factory()->create()->id]);
        $project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->actingAs($agent)
                ->postJson(route('api.webmcp.culling.analyze', $project))
                ->assertOk();
        }

        $this->actingAs($agent)
            ->postJson(route('api.webmcp.culling.analyze', $project))
            ->assertTooManyRequests();
    }
}
