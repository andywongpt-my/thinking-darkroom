<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\Project;
use App\Models\QaFinding;
use App\Models\User;
use App\Services\Media\MediaStore;
use App\Services\ProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AuditFixRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorld(): array
    {
        $photographer = User::factory()->create(['name' => 'Photographer']);
        $agent = User::factory()->create(['is_agent' => true, 'name' => 'Agent']);
        $project = Project::factory()->withPhotos(2)->create(['owner_id' => $photographer->id]);
        $project->members()->syncWithoutDetaching([
            $photographer->id => ['role' => Domain::ROLE_OWNER],
            $agent->id => ['role' => Domain::ROLE_AGENT],
        ]);
        $project->photos()->update([
            'selection_state' => Domain::SELECTION_UNREVIEWED,
            'retouch_state' => Domain::RETOUCH_NONE,
        ]);

        return [$photographer, $agent, $project];
    }

    /* -------- Sol P0-1: durable runtime must fail closed without Blob -------- */

    public function test_durable_runtime_refuses_ephemeral_media_writes(): void
    {
        putenv('BLOB_READ_WRITE_TOKEN=');
        putenv('DB_CONNECTION=pgsql');
        putenv('VERCEL=1'); // simulate the serverless durable runtime

        try {
            app(MediaStore::class)->writeBytes('project-1', 'bytes', 'a.jpg', 'image/jpeg');
            $this->fail('A durable runtime without a Blob token must fail closed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Durable media backend unavailable', $e->getMessage());
        } finally {
            putenv('DB_CONNECTION=sqlite');
            putenv('VERCEL');
        }
    }

    public function test_local_sqlite_runtime_keeps_public_disk_fallback(): void
    {
        putenv('BLOB_READ_WRITE_TOKEN=');
        putenv('DB_CONNECTION=sqlite');

        $stored = app(MediaStore::class)->writeBytes('project-1', 'hello', 'a.txt', 'text/plain');

        $this->assertStringNotContainsString('http', $stored['path']);
    }

    /* -------- Sol P1-5: contradictory terminal reviews are serialized -------- */

    public function test_a_proposal_cannot_be_reviewed_twice(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();

        $proposal = app(ProposalService::class)->createProposal(
            $project, $agent, Domain::TYPE_CULL, [['photo_id' => $photo->id, 'action' => 'cull']],
        );
        app(ProposalService::class)->approve($proposal, $photographer);

        $this->expectException(\LogicException::class);
        app(ProposalService::class)->reject($proposal->fresh(), $photographer);
    }

    /* -------- Sol P1-6: execute response carries the execution summary -------- */

    public function test_execute_response_exposes_execution_summary(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $photo = $project->photos()->first();

        $proposal = app(ProposalService::class)->createProposal(
            $project, $agent, Domain::TYPE_CULL,
            [['photo_id' => $photo->id, 'action' => 'cull']],
        );
        app(ProposalService::class)->approve($proposal, $photographer);

        $response = $this->actingAs($agent)
            ->postJson(route('api.webmcp.proposals.execute', [$project->id, $proposal->id]))
            ->assertOk();

        $this->assertArrayHasKey('payload', $response->json());
    }

    /* -------- Sol P2-7: QA reviewer notes are persisted -------- */

    public function test_qa_review_note_is_persisted(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $finding = QaFinding::create([
            'project_id' => $project->id,
            'photo_id' => null,
            'severity' => 'warning',
            'category' => 'consistency',
            'message' => 'drift',
            'details' => [],
            'status' => 'open',
        ]);

        $this->actingAs($photographer)
            ->postJson(route('qa-findings.respond', [$project->id, $finding->id]), [
                'action' => 'acknowledge', 'note' => 'intentional warm look',
            ])
            ->assertOk();

        $finding->refresh();
        $this->assertSame('intentional warm look', $finding->details['review']['note']);
        $this->assertSame($photographer->id, $finding->details['review']['reviewer_id']);
    }

    /* -------- Sol P2-11: registration is throttled -------- */

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('register'), [
                'name' => 'User '.$i,
                'email' => "user-{$i}@example.test",
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);
            // Each success logs the new user in; the guest middleware would
            // redirect the next attempt to /dashboard before the throttle
            // ever counts it. Reset to an anonymous client each round.
            auth()->guard('web')->logout();
            session()->flush();
        }

        $this->post(route('register'), [
            'name' => 'Overflow',
            'email' => 'overflow@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertTooManyRequests();
    }

    /* -------- AGY P0: agent bearer tokens work end to end -------- */

    public function test_agent_bearer_token_can_read_workspace_context(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();

        $token = $agent->createToken('agent-cli')->plainTextToken;

        $this->withToken($token)
            ->getJson(route('api.webmcp.context', $project))
            ->assertOk();
    }
}
