<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\BrainstormSession;
use App\Models\CreativeBrief;
use App\Models\CreativeConcept;
use App\Models\Project;
use App\Models\User;
use App\Services\CreativeRoomService;
use App\Support\WebmcpToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Sprint 2 — Creative Room: concept lifecycle, human-only creative authority,
 * single-adopted-direction invariant, lineage, project isolation, brief
 * persistence and the WebMCP tool surface.
 *
 * Authority model enforced at THREE layers (all tested here):
 *  1. WebMCP catalogue contains no adoption/approval tool;
 *  2. controller route policy (owner/photographer only);
 *  3. service-level guard (account-level is_agent + project-role).
 */
class CreativeRoomTest extends TestCase
{
    use RefreshDatabase;

    private CreativeRoomService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CreativeRoomService::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{0: User, 1: User, 2: Project}
     */
    private function makeWorld(): array
    {
        $photographer = User::factory()->create(['name' => 'Maya Photographer']);
        $agent = User::factory()->create(['is_agent' => true, 'name' => 'Agent']);
        $project = Project::factory()->create(['owner_id' => $photographer->id]);
        $project->members()->syncWithoutDetaching([
            $photographer->id => ['role' => Domain::ROLE_OWNER],
            $agent->id => ['role' => Domain::ROLE_AGENT],
        ]);

        return [$photographer, $agent, $project];
    }

    private function conceptContent(): array
    {
        return [
            'mood' => ['quiet', 'coastal'],
            'story' => ['solitude before the crowd arrives'],
            'composition' => ['wide establishing, subject off-center'],
            'lighting' => ['soft overcast window light'],
            'color' => ['muted blues, warm skin tones'],
            'subject_direction' => ['candid movement, minimal posing'],
            'selection_priorities' => ['emotion' => 'primary', 'technical' => 'secondary'],
            'retouch_philosophy' => 'invisible retouch, keep texture',
            'avoid' => ['heavy HDR', 'studio-look posing'],
        ];
    }

    private function proposeOne(Project $project, User $creator, string $title = 'Concept A'): CreativeConcept
    {
        return $this->service->proposeConcept(
            $project, $creator, null, $title,
            'Summary for '.$title,
            $this->conceptContent(),
            [['dimension' => 'mood', 'label' => 'quiet', 'value' => 'hushed morning']],
        );
    }

    private function openSession(Project $project, User $photographer): BrainstormSession
    {
        return $this->service->openBrainstorm(
            $project, $photographer,
            'Chasing the five minutes of fog before sunrise; everything else secondary.',
        );
    }

    public function test_creative_room_page_bootstraps_conversation_presence_current_user_and_chat_permission(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $messageBody = 'Please explain how this direction serves the set.';

        $this->actingAs($photographer)
            ->postJson(route('agent-conversation.store', $project), [
                'body' => $messageBody,
                'client_message_id' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $this->actingAs($agent)
            ->postJson(route('api.presence.heartbeat', $project))
            ->assertOk();

        $this->actingAs($photographer)
            ->get(route('creative.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CreativeRoom')
                ->where('request.user.id', $photographer->id)
                ->where('request.user.name', 'Maya Photographer')
                ->where('request.user.is_agent', false)
                ->where('request.user.presence_eligible', false)
                ->where('presence.project_id', $project->id)
                ->where('presence.online', true)
                ->where('permissions.can_chat', true)
                ->where('conversation.project_id', $project->id)
                ->where('conversation.trust_boundary', 'untrusted_project_conversation')
                ->has('conversation.messages', 1)
                ->where('conversation.messages.0.body', $messageBody));

        $this->actingAs($agent)
            ->get(route('creative.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CreativeRoom')
                ->where('request.user.id', $agent->id)
                ->where('request.user.is_agent', true)
                ->where('request.user.presence_eligible', true)
                ->where('permissions.can_chat', true));
    }

    /* ------------------------------------------------------------------ */
    /*  1–3. Propose / propose ≠ adopt / photographer adopts */
    /* ------------------------------------------------------------------ */

    public function test_agent_can_propose_concepts(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $session = $this->openSession($project, $photographer);

        $concept = $this->service->proposeConcept(
            $project, $agent, $session, 'Fog Walk',
            'Solitary figure dissolving into fog.',
            $this->conceptContent(),
            [['dimension' => 'mood', 'label' => 'quiet', 'value' => 'hushed morning']],
        );

        $this->assertInstanceOf(CreativeConcept::class, $concept);
        $this->assertSame($project->id, $concept->project_id);
        $this->assertSame($session->id, $concept->brainstorm_session_id);
        $this->assertSame(Domain::CONCEPT_STATUS_PROPOSED, $concept->status);
        $concept->load('items');
        $this->assertNotNull($concept->items->first(), 'proposed trait item persisted');
        $this->assertSame('agent', $concept->items->first()->source);
    }

    public function test_proposing_does_not_adopt(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();

        $this->service->proposeConcept($project, $agent, null, 'Fog Walk', null, $this->conceptContent());

        $this->assertNull($project->currentCreativeDirection());
        $this->assertNull($project->creativeBriefs()->where('status', 'active')->first());
        $this->assertSame(0, CreativeConcept::where('status', Domain::CONCEPT_STATUS_ADOPTED)->count());
    }

    public function test_photographer_can_adopt(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $concept = $this->proposeOne($project, $agent);

        $adopted = $this->service->adoptConcept($project, $photographer, $concept, 'this is the one');

        $this->assertSame(Domain::CONCEPT_STATUS_ADOPTED, $adopted->status);
        $this->assertNotNull($adopted->adopted_at);
        $this->assertSame($concept->id, $project->currentCreativeDirection()->id);
        // Structured brief derived + persisted on adoption.
        $brief = $project->creativeBriefs()->where('status', 'active')->first();
        $this->assertNotNull($brief);
        $this->assertSame($concept->title, $brief->creative_direction);
    }

    public function test_human_review_endpoints_persist_optional_adoption_and_rejection_notes(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $rejected = $this->proposeOne($project, $agent, 'Not This Direction');
        $rejectNote = 'The palette fights the intended quiet morning mood.';

        $this->actingAs($photographer)
            ->postJson(route('creative.concepts.reject', [$project, $rejected]), ['note' => $rejectNote])
            ->assertOk();

        $this->assertDatabaseHas('photographer_decisions', [
            'project_id' => $project->id,
            'photographer_id' => $photographer->id,
            'decision' => 'reject_concept',
            'note' => "Rejected concept #{$rejected->id} ({$rejected->title}) — {$rejectNote}",
        ]);

        $adopted = $this->proposeOne($project, $agent, 'The Direction');
        $adoptNote = 'This keeps the subject intimate without losing structure.';

        $this->actingAs($photographer)
            ->postJson(route('creative.concepts.adopt', [$project, $adopted]), ['note' => $adoptNote])
            ->assertOk();

        $this->assertDatabaseHas('photographer_decisions', [
            'project_id' => $project->id,
            'photographer_id' => $photographer->id,
            'decision' => 'adopt_concept',
            'note' => "Adopted creative direction: {$adopted->title} — {$adoptNote}",
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  4–7. Authority: account-level agent, project-role agent, viewer */
    /* ------------------------------------------------------------------ */

    public function test_account_level_agent_cannot_adopt(): void
    {
        [, $agent, $project] = $this->makeWorld();
        $concept = $this->proposeOne($project, $agent);

        $this->expectException(\LogicException::class);
        $this->service->adoptConcept($project, $agent, $concept);
    }

    public function test_project_role_agent_cannot_adopt_even_when_account_is_not_agent(): void
    {
        [$photographer, , $project] = $this->makeWorld();
        $humanWithAgentRole = User::factory()->create(['is_agent' => false, 'name' => 'Human w/ agent role']);
        $project->members()->syncWithoutDetaching([
            $humanWithAgentRole->id => ['role' => Domain::ROLE_AGENT],
        ]);
        $concept = $this->proposeOne($project, $photographer);

        $this->assertFalse($humanWithAgentRole->isAgent());

        $this->expectException(\LogicException::class);
        $this->service->adoptConcept($project, $humanWithAgentRole, $concept);
    }

    public function test_viewer_cannot_adopt(): void
    {
        [$photographer, , $project] = $this->makeWorld();
        $viewer = User::factory()->create(['is_agent' => false, 'name' => 'Viewer']);
        $project->members()->syncWithoutDetaching([
            $viewer->id => ['role' => Domain::ROLE_VIEWER],
        ]);
        $concept = $this->proposeOne($project, $photographer);

        $this->expectException(\LogicException::class);
        $this->service->adoptConcept($project, $viewer, $concept);
    }

    public function test_only_owner_or_photographer_role_may_commit_direction(): void
    {
        [$photographer, , $project] = $this->makeWorld();
        // photographer ROLE (non-owner human) is allowed.
        $photog = User::factory()->create(['is_agent' => false]);
        $project->members()->syncWithoutDetaching([
            $photog->id => ['role' => Domain::ROLE_PHOTOGRAPHER],
        ]);
        $concept = $this->proposeOne($project, $photographer);

        $adopted = $this->service->adoptConcept($project, $photog, $concept);
        $this->assertSame(Domain::CONCEPT_STATUS_ADOPTED, $adopted->status);

        // viewer denied through the helper as well.
        $viewer = User::factory()->create(['is_agent' => false]);
        $project->members()->syncWithoutDetaching([
            $viewer->id => ['role' => Domain::ROLE_VIEWER],
        ]);
        $this->assertFalse($this->service->hasCreativeAuthority($project, $viewer));
        $this->assertTrue($this->service->hasCreativeAuthority($project, $photog));
    }

    /* ------------------------------------------------------------------ */
    /*  8–9. Single-adopted-direction invariant + rejected protection */
    /* ------------------------------------------------------------------ */

    public function test_second_adoption_supersedes_the_prior_direction(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $first = $this->proposeOne($project, $agent, 'First Direction');
        $second = $this->proposeOne($project, $agent, 'Second Direction');

        $this->service->adoptConcept($project, $photographer, $first);
        $this->service->adoptConcept($project, $photographer, $second);

        $current = CreativeConcept::where('project_id', $project->id)
            ->where('status', Domain::CONCEPT_STATUS_ADOPTED)
            ->get();
        $this->assertCount(1, $current, 'exactly one current adopted direction');
        $this->assertSame($second->id, $current->first()->id);

        // Prior direction demoted deterministically to SUPERSEDED — history intact.
        $this->assertSame(
            Domain::CONCEPT_STATUS_SUPERSEDED,
            $first->fresh()->status,
            'prior adopted direction becomes superseded, never deleted',
        );
        // Latest brief wins; older briefs superseded, not deleted.
        $this->assertSame(1, $project->creativeBriefs()->where('status', 'active')->count());
    }

    public function test_rejected_concept_cannot_be_adopted(): void
    {
        [$photographer, , $project] = $this->makeWorld();
        $concept = $this->proposeOne($project, $photographer);

        $this->service->rejectConcept($project, $photographer, $concept);
        $this->assertSame(Domain::CONCEPT_STATUS_REJECTED, $concept->fresh()->status);

        try {
            $this->service->adoptConcept($project, $photographer, $concept->fresh());
            $this->fail('Adopting a rejected concept must fail.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Cannot change a concept in state', $e->getMessage());
        }

        $this->assertNull($project->currentCreativeDirection());
        $this->assertSame(Domain::CONCEPT_STATUS_REJECTED, $concept->fresh()->status);
    }

    /* ------------------------------------------------------------------ */
    /*  10–11. Lineage: explore preserves parent, merge preserves both */
    /* ------------------------------------------------------------------ */

    public function test_explore_preserves_parent_lineage(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $parent = $this->proposeOne($project, $agent, 'Parent');
        $child = $this->service->proposeConceptRevision(
            $project, $agent, $parent, 'Child',
            'Bolder variation.', $this->conceptContent(),
        );

        $this->assertSame($parent->id, $child->parent_concept_id);
        $this->assertSame(Domain::CONCEPT_STATUS_PROPOSED, $parent->fresh()->status, 'parent untouched');

        $explored = $this->service->exploreConcept($project, $photographer, $child);
        $this->assertSame(Domain::CONCEPT_STATUS_EXPLORING, $explored->status);
        $this->assertSame($parent->id, $explored->fresh()->parent_concept_id, 'lineage preserved through explore');
    }

    public function test_merge_preserves_both_source_lineage_references(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $a = $this->proposeOne($project, $agent, 'Source A');
        $b = $this->proposeOne($project, $agent, 'Source B');

        $merged = $this->service->proposeConceptMerge(
            $project, $agent,
            [
                ['concept_id' => $a->id, 'note' => 'palette'],
                ['concept_id' => $b->id, 'note' => 'subject treatment'],
            ],
            'Merged Direction', 'Combines A + B.', $this->conceptContent(),
        );

        $basis = $merged->lineage_basis;
        $this->assertCount(2, $basis);
        $ids = array_column($basis, 'concept_id');
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        $this->assertSame(Domain::CONCEPT_STATUS_MERGED, $merged->status);

        // Sources untouched.
        $this->assertSame(Domain::CONCEPT_STATUS_PROPOSED, $a->fresh()->status);
        $this->assertSame(Domain::CONCEPT_STATUS_PROPOSED, $b->fresh()->status);
    }

    /* ------------------------------------------------------------------ */
    /*  12 + 16. Project isolation / unauthorized cross-project access */
    /* ------------------------------------------------------------------ */

    public function test_project_isolation_concept_operations(): void
    {
        [$photographer, , $project] = $this->makeWorld();
        $other = Project::factory()->create(['owner_id' => $photographer->id]);
        $other->members()->syncWithoutDetaching([
            $photographer->id => ['role' => Domain::ROLE_OWNER],
        ]);
        $foreign = $this->proposeOne($other, $photographer, 'Foreign Concept');

        $this->expectException(\LogicException::class);
        $this->service->adoptConcept($project, $photographer, $foreign);
    }

    public function test_unauthorized_cross_project_webmcp_access_fails(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $outsider = User::factory()->create(['is_agent' => true]);
        // outsider is NOT a member of $project.

        $this->actingAs($outsider)
            ->getJson("/api/projects/{$project->id}/creative/concepts")
            ->assertStatus(403);
    }

    /* ------------------------------------------------------------------ */
    /*  13 + 18. Creative brief persists + structuredIntent contract */
    /* ------------------------------------------------------------------ */

    public function test_creative_brief_persists_on_adoption(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $concept = $this->proposeOne($project, $agent);

        $this->service->adoptConcept($project, $photographer, $concept);

        $brief = CreativeBrief::where('project_id', $project->id)
            ->where('status', 'active')
            ->first();

        $this->assertNotNull($brief, 'adoption must persist a structured brief');
        $payload = $brief->payload;
        $this->assertSame(['quiet', 'coastal'], $payload['mood']);
        $this->assertSame(['solitude before the crowd arrives'], $payload['emotional_intent']);
        $this->assertSame(['soft overcast window light'], $payload['lighting']);
        $this->assertSame('invisible retouch, keep texture', $payload['retouch']);
        $this->assertSame(['heavy HDR', 'studio-look posing'], $payload['avoid']);
    }

    public function test_structured_intent_contract_returns_expected_shape(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $concept = $this->proposeOne($project, $agent, 'Fog Walk');

        // No direction → null (clean Sprint 3 handshake).
        $this->assertNull($this->service->structuredIntentFor($project));

        $this->service->adoptConcept($project, $photographer, $concept);

        $intent = $this->service->structuredIntentFor($project);

        $this->assertIsArray($intent);
        $this->assertSame($project->id, $intent['project_id']);
        $this->assertTrue($intent['has_direction']);
        $this->assertSame('Fog Walk', $intent['adopted_concept']['title']);
        $this->assertSame(Domain::CONCEPT_STATUS_ADOPTED, $intent['adopted_concept']['status']);
        $this->assertSame(['quiet', 'coastal'], $intent['brief']['mood']);
        $this->assertSame(['quiet', 'coastal'], $intent['intent']['mood']);
        $this->assertArrayHasKey('selection_priority', $intent['intent']);
        $this->assertArrayHasKey('avoid', $intent['intent']);
        $this->assertArrayHasKey('philosophy', $intent['intent']);
    }

    /* ------------------------------------------------------------------ */
    /*  14 + 15. WebMCP retrieves direction + proposals are audited */
    /* ------------------------------------------------------------------ */

    public function test_webmcp_get_creative_direction_returns_adopted_brief(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $concept = $this->proposeOne($project, $agent, 'WebMCP Direction');
        $this->service->adoptConcept($project, $photographer, $concept);

        $res = $this->actingAs($agent)
            ->getJson("/api/projects/{$project->id}/creative/direction");

        $res->assertOk();
        $body = $res->json();
        $this->assertTrue($body['has_direction']);
        $this->assertSame('WebMCP Direction', $body['adopted_concept']['title']);
        $this->assertSame(['quiet', 'coastal'], $body['brief']['mood']);
        $this->assertSame(['quiet', 'coastal'], $body['intent']['mood']);
    }

    public function test_webmcp_propose_concepts_is_audited(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();

        $res = $this->actingAs($agent)
            ->postJson("/api/projects/{$project->id}/creative/concepts", [
                'concepts' => [
                    ['title' => 'Audited A', 'content' => $this->conceptContent()],
                    ['title' => 'Audited B', 'content' => $this->conceptContent()],
                    ['title' => 'Audited C', 'content' => $this->conceptContent()],
                ],
            ]);

        $res->assertCreated();
        $this->assertCount(3, $res->json('concepts'));
        $this->assertTrue(collect($res->json('concepts'))->every(
            fn ($c) => $c['status'] === Domain::CONCEPT_STATUS_PROPOSED,
        ));

        $this->assertDatabaseHas('agent_tool_calls', [
            'project_id' => $project->id,
            'tool_name' => 'propose_concepts',
            'authority' => Domain::AUTHORITY_PROPOSE,
            'result_status' => Domain::RESULT_COMPLETED,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  17. No forbidden final-authority tool in the catalogue */
    /* ------------------------------------------------------------------ */

    public function test_no_forbidden_final_authority_tool_exists_in_catalogue(): void
    {
        $names = array_keys(WebmcpToolCatalog::all());

        foreach (Domain::FORBIDDEN_CREATIVE_DIRECTION_TOOLS as $banned) {
            $this->assertNotContains($banned, $names, "forbidden tool [{$banned}] must never exist");
        }

        // And no name even hints at human-final authority.
        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression(
                '/adopt|approve_concept|set_final|bypass|force_/',
                $name,
                "tool [{$name}] violates the human-authority naming boundary",
            );
        }

        // Sprint 2 inventory: exactly 4 READ + 4 PROPOSE creative tools.
        $catalog = WebmcpToolCatalog::all();
        $sprint2 = array_intersect_key($catalog, array_flip([
            'get_brainstorm_context', 'get_creative_direction', 'list_concepts', 'get_concept',
            'propose_concepts', 'propose_concept_revision', 'propose_concept_merge', 'propose_creative_brief',
        ]));
        $this->assertCount(8, $sprint2);
        $this->assertSame(4, count(array_filter($sprint2, fn ($t) => $t['read_only'])));
    }

    /* ------------------------------------------------------------------ */
    /*  Extra: WebMCP tool actions cannot adopt (defense in depth) */
    /* ------------------------------------------------------------------ */

    public function test_webmcp_concept_endpoints_never_change_adoption_state(): void
    {
        [$photographer, $agent, $project] = $this->makeWorld();
        $a = $this->proposeOne($project, $agent, 'Source A');
        $b = $this->proposeOne($project, $agent, 'Source B');

        // Agent merges through the WebMCP tool endpoint — status stays non-adopted.
        $this->actingAs($agent)
            ->postJson("/api/projects/{$project->id}/creative/merge", [
                'sources' => [
                    ['concept_id' => $a->id],
                    ['concept_id' => $b->id],
                ],
                'title' => 'Merged via tool',
                'content' => $this->conceptContent(),
            ])
            ->assertCreated();

        $this->assertNull($project->currentCreativeDirection());
        $this->assertSame(0, CreativeConcept::where('status', Domain::CONCEPT_STATUS_ADOPTED)->count());

        // And the human-only adopt endpoint refuses an agent account.
        $this->actingAs($agent)
            ->postJson("/projects/{$project->id}/creative/concepts/{$a->id}/adopt")
            ->assertStatus(403);

        $this->assertSame(0, CreativeConcept::where('status', Domain::CONCEPT_STATUS_ADOPTED)->count());
    }

    public function test_human_review_endpoints_enforce_project_role(): void
    {
        [$photographer, , $project] = $this->makeWorld();
        $viewer = User::factory()->create(['is_agent' => false]);
        $project->members()->syncWithoutDetaching([
            $viewer->id => ['role' => Domain::ROLE_VIEWER],
        ]);
        $concept = $this->proposeOne($project, $photographer);

        // Viewer blocked at the HTTP layer.
        $this->actingAs($viewer)
            ->postJson("/projects/{$project->id}/creative/concepts/{$concept->id}/adopt")
            ->assertStatus(403);

        // Viewer blocked at the service layer too.
        $this->assertFalse($this->service->hasCreativeAuthority($project, $viewer));
    }
}
