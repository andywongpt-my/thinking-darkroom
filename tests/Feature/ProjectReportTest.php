<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\Photo;
use App\Models\PhotoDerivative;
use App\Models\PhotographerDecision;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\QaFinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Session Report — post-execution handback surface.
 *
 * The report closes the photographer↔agent loop: after proposals are
 * reviewed and executed, this page (and its Markdown export) summarize
 * selection → proposals → decisions → findings → deliverables.
 *
 * Authority contract under test:
 *  - owner/photographer members: 200 (page + markdown)
 *  - agent accounts (even project members): 403 — human-only surface
 *  - non-members: 403
 *  - payload honestly counts executed/reverted derivatives
 */
class ProjectReportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Project} */
    private function makeProjectWithOwner(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'owner_id' => $owner->id,
            'name' => 'Golden Hour',
            'description' => 'Waterfront engagement set.',
        ]);
        $project->members()->attach($owner->id, ['role' => Domain::ROLE_OWNER]);

        return [$owner, $project];
    }

    private function seedExecutedSession(Project $project, User $photographer): void
    {
        Photo::factory()->count(2)->create([
            'project_id' => $project->id,
            'selection_state' => Domain::SELECTION_SELECTED,
        ]);
        Photo::factory()->create([
            'project_id' => $project->id,
            'selection_state' => Domain::SELECTION_CULLED,
            'filename' => 'IMG_CULL.jpg',
        ]);

        $proposal = Proposal::factory()->create([
            'project_id' => $project->id,
            'created_by' => $photographer->id,
            'type' => Domain::TYPE_RETOUCH,
            'status' => Domain::STATE_EXECUTED,
            'summary' => 'Lift exposure on hero frames',
            'executed_at' => now(),
        ]);

        PhotographerDecision::create([
            'project_id' => $project->id,
            'proposal_id' => $proposal->id,
            'photographer_id' => $photographer->id,
            'decision' => 'approve',
            'note' => 'Brighter, keep contrast',
        ]);

        QaFinding::create([
            'project_id' => $project->id,
            'proposal_id' => $proposal->id,
            'severity' => 'warning',
            'category' => 'exposure',
            'message' => 'Two frames still read flat',
            'status' => 'open',
        ]);

        $selected = $project->photos()->where('selection_state', Domain::SELECTION_SELECTED)->orderBy('id')->get();
        $heroPhoto = $selected[0];
        $secondPhoto = $selected[1];

        PhotoDerivative::create([
            'project_id' => $project->id,
            'photo_id' => $heroPhoto->id,
            'proposal_id' => $proposal->id,
            'type' => 'retouched',
            'storage_path' => 'project-1/derivatives/IMG_HERO_v1.jpg',
            'adjustments' => ['exposure' => 10, 'contrast' => 5],
            'provenance' => 'lut6key:v1',
            'created_by' => $photographer->id,
        ]);

        // Reverted derivative on a DIFFERENT photo — the schema enforces one
        // derivative per (photo, type), so the revert story needs its own row.
        PhotoDerivative::create([
            'project_id' => $project->id,
            'photo_id' => $secondPhoto->id,
            'proposal_id' => $proposal->id,
            'type' => 'retouched',
            'storage_path' => 'project-1/derivatives/IMG_SECOND_v0.jpg',
            'adjustments' => ['exposure' => 20],
            'provenance' => 'lut6key:v1',
            'created_by' => $photographer->id,
            'reverted_at' => now(),
        ]);
    }

    public function test_owner_can_view_report_page_with_full_payload(): void
    {
        [$owner, $project] = $this->makeProjectWithOwner();
        $this->seedExecutedSession($project, $owner);

        $this->actingAs($owner)
            ->get(route('projects.report.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProjectReport')
                ->where('report.project.id', $project->id)
                ->where('report.project.name', 'Golden Hour')
                ->where('report.selection.total', 3)
                ->where('report.selection.selected', 2)
                ->where('report.selection.culled', 1)
                ->where('report.counts.proposals', 1)
                ->where('report.counts.proposals_executed', 1)
                ->where('report.counts.decisions', 1)
                ->where('report.counts.findings_open', 1)
                ->where('report.counts.derivatives_active', 1)
                ->where('report.counts.derivatives_reverted', 1)
                ->has('report.proposals', 1)
                ->where('report.proposals.0.status', Domain::STATE_EXECUTED)
                ->has('report.decisions', 1)
                ->where('report.decisions.0.decision', 'approve')
                ->has('report.findings', 1)
                ->has('report.derivatives', 2));
    }

    public function test_report_markdown_downloads_with_full_trail(): void
    {
        [$owner, $project] = $this->makeProjectWithOwner();
        $this->seedExecutedSession($project, $owner);

        $response = $this->actingAs($owner)
            ->get(route('projects.report.markdown', $project));

        $response->assertOk();
        $this->assertStringContainsString('text/markdown', (string) $response->headers->get('Content-Type'));

        $markdown = $response->getContent();
        $proposalId = $project->proposals()->first()->id;
        $this->assertStringContainsString('# Session Report — Golden Hour', $markdown);
        $this->assertStringContainsString('Photos: 3 (2 selected, 1 culled, 0 unreviewed)', $markdown);
        $this->assertStringContainsString('Proposals: 1 (1 executed)', $markdown);
        $this->assertStringContainsString("**#{$proposalId} retouch** — Lift exposure on hero frames (executed)", $markdown);
        $this->assertStringContainsString('→ **approve** (proposal #', $markdown);
        $this->assertStringContainsString('note: Brighter, keep contrast', $markdown);
        $this->assertStringContainsString('[warning/exposure] Two frames still read flat (open)', $markdown);
        $this->assertStringContainsString('Deliverables: 1 active (1 reverted)', $markdown);
        $this->assertStringContainsString('contrast=5', $markdown);
        $this->assertStringContainsString('exposure=10', $markdown);
        $this->assertStringContainsString('provenance: lut6key:v1', $markdown);
        $this->assertStringContainsString('[REVERTED]', $markdown);
        $this->assertStringContainsString('approved by the photographer before execution', $markdown);
    }

    public function test_report_markdown_is_honest_for_an_empty_project(): void
    {
        [$owner, $project] = $this->makeProjectWithOwner();

        $markdown = $this->actingAs($owner)
            ->get(route('projects.report.markdown', $project))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Photos: 0 (0 selected, 0 culled, 0 unreviewed)', $markdown);
        $this->assertStringContainsString('_No proposals were made in this session._', $markdown);
        $this->assertStringContainsString('_No decisions recorded._', $markdown);
        $this->assertStringContainsString('_No derivatives were executed in this session._', $markdown);
    }

    public function test_agent_members_are_forbidden_from_report_page_and_markdown(): void
    {
        [$owner, $project] = $this->makeProjectWithOwner();
        $agent = User::factory()->agent()->create();
        $project->members()->attach($agent->id, ['role' => Domain::ROLE_AGENT]);

        $this->actingAs($agent)
            ->get(route('projects.report.show', $project))
            ->assertForbidden();

        $this->actingAs($agent)
            ->get(route('projects.report.markdown', $project))
            ->assertForbidden();
    }

    public function test_non_members_are_forbidden_from_the_report(): void
    {
        [$owner, $project] = $this->makeProjectWithOwner();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('projects.report.show', $project))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->get(route('projects.report.markdown', $project))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        [$owner, $project] = $this->makeProjectWithOwner();

        $this->get(route('projects.report.show', $project))->assertRedirect(route('login'));
        $this->get(route('projects.report.markdown', $project))->assertRedirect(route('login'));
    }
}
