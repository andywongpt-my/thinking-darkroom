<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\AgentToolCall;
use App\Models\CreativeConcept;
use App\Models\Photo;
use App\Models\PhotoObservationRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\AgentLlmService;
use App\Services\CreativeRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * The grounded LLM reasoning path for agent turns: config-gated, evidence
 * serialized from persisted observations only, audit-recorded, and always
 * falling back to the deterministic composer when the provider is absent or
 * failing. Authority must never widen because a model is in the loop.
 */
class AgentLlmTurnTest extends TestCase
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
        // PhotoFactory randomizes selection_state; pin every photo to
        // "selected" so tests that depend on cull eligibility are
        // deterministic instead of faker-dependent.
        Photo::query()->where('project_id', $this->project->id)->update([
            'selection_state' => Domain::SELECTION_SELECTED,
        ]);
        $this->project->members()->sync([
            $this->photographer->id => ['role' => Domain::ROLE_OWNER],
            $this->agent->id => ['role' => Domain::ROLE_AGENT],
        ]);

        config([
            'services.agent_llm.base_url' => 'https://llm.test/api/v1',
            'services.agent_llm.key' => 'test-key',
            'services.agent_llm.model' => 'test-model:free',
            'services.agent_llm.timeout' => 5,
        ]);
    }

    public function test_enabled_requires_key_and_model(): void
    {
        $service = app(AgentLlmService::class);
        $this->assertTrue($service->enabled());

        config(['services.agent_llm.key' => null]);
        $this->assertFalse(app(AgentLlmService::class)->enabled());

        config(['services.agent_llm.key' => 'k', 'services.agent_llm.model' => null]);
        $this->assertFalse(app(AgentLlmService::class)->enabled());
    }

    public function test_llm_turn_answers_from_persisted_evidence_and_audits_the_reasoning(): void
    {
        Http::fake([
            'llm.test/api/v1/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Frame 02 is the strongest keeper here: the pixel analysis shows '
                                .'it technically sound while its burst siblings carry blur risk. '
                                .'The photographer decides what to keep.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), [
                'trigger_id' => $this->createHumanTrigger('Which frames carry this burst, and why?'),
            ])
            ->assertOk();

        $body = (string) $response->json('message.body');
        $this->assertStringContainsString('Frame 02', $body);

        // The reasoning step is visible in the audit ledger as an ANALYZE call.
        $audit = AgentToolCall::query()
            ->where('project_id', $this->project->id)
            ->where('tool_name', 'llm_reasoning')
            ->firstOrFail();

        $this->assertSame(Domain::AUTHORITY_ANALYZE, $audit->authority);
        $this->assertSame('test-model:free', $audit->input['model']);
        $this->assertSame(200, $audit->output_summary['http_status']);
        $this->assertGreaterThan(0, $audit->output_summary['reply_chars']);

        // The request must have carried a system prompt with the authority
        // constraints and a JSON evidence payload built from real data.
        Http::assertSent(function ($request) {
            $payload = $request->data();

            return str_contains((string) $payload['messages'][0]['content'], 'NEVER claim to have changed')
                && str_contains((string) $payload['messages'][1]['content'], '"photos"')
                && str_contains((string) $payload['messages'][1]['content'], 'deterministic_recommendation');
        });
    }

    public function test_provider_failure_falls_back_to_the_deterministic_composer(): void
    {
        Http::fake([
            'llm.test/api/v1/*' => Http::response(['error' => ['code' => 503]], 503),
        ]);

        $response = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), [
                'trigger_id' => $this->createHumanTrigger('Any thoughts?'),
            ])
            ->assertOk();

        $this->assertStringContainsString('I reviewed the project: 2 photos', (string) $response->json('message.body'));
        $this->assertStringContainsString('the photographer decides', (string) $response->json('message.body'));
    }

    public function test_unconfigured_key_falls_back_without_any_http_call(): void
    {
        config(['services.agent_llm.key' => null]);

        Http::fake();

        $response = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), [
                'trigger_id' => $this->createHumanTrigger('Hello?'),
            ])
            ->assertOk();

        Http::assertNothingSent();
        $this->assertStringContainsString('I reviewed the project: 2 photos', (string) $response->json('message.body'));
    }

    public function test_llm_reply_is_never_resent_when_the_trigger_is_replayed(): void
    {
        Http::fake([
            'llm.test/api/v1/*' => Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'Grounded read: keep 01, review the soft frames.']],
                ],
            ], 200),
        ]);

        $triggerId = $this->createHumanTrigger('What should I look at first?');
        $payload = ['trigger_id' => $triggerId];

        $first = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), $payload)
            ->assertOk();
        $second = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), $payload)
            ->assertOk();

        $this->assertSame($first->json('message.id'), $second->json('message.id'));
    }

    public function test_cull_intent_with_llm_still_creates_exactly_one_proposal(): void
    {
        // Adopt a technical-first direction so the soft frame becomes a
        // reject_candidate (the deterministic proposal path needs one).
        $concept = CreativeConcept::query()->create([
            'project_id' => $this->project->id,
            'title' => 'Technical precision test direction',
            'summary' => 'Precision-first direction for the cull test.',
            'content' => [
                'mood' => ['clean', 'precise'],
                'selection_priorities' => ['technical' => 'primary', 'emotion' => 'secondary'],
                'avoid' => ['soft focus'],
            ],
            'status' => Domain::CONCEPT_STATUS_PROPOSED,
            'created_by' => $this->agent->id,
        ]);
        app(CreativeRoomService::class)
            ->adoptConcept($this->project, $this->photographer, $concept, 'Test setup');

        // One soft frame with persisted observation: technical score 0.25 -
        // 0.3 softness penalty => weak => reject_candidate under the brief.
        $photo = $this->project->photos()->first();
        PhotoObservationRecord::create([
            'photo_id' => $photo->id,
            'project_id' => $this->project->id,
            'payload' => [
                'technical' => [
                    'sharpness' => ['assessment' => 'soft', 'confidence' => 0.9],
                    'exposure' => ['assessment' => 'correct', 'confidence' => 0.9],
                    'motion_blur' => ['assessment' => 'none', 'confidence' => 0.9],
                    'highlight_clipping' => ['assessment' => 'safe', 'confidence' => 0.9],
                ],
                'creative' => [
                    'expression' => 'neutral', 'candidness' => 'candid', 'environmental_storytelling' => 'weak',
                    'mood' => [], 'compositional_fit' => 'unknown', 'emotion_strength' => 'low',
                ],
            ],
            'provider' => Domain::OBSERVATION_PROVIDER_DEMO,
            'provenance' => Domain::OBSERVATION_PROVENANCES[Domain::OBSERVATION_PROVIDER_DEMO],
            'similarity_group' => null,
        ]);

        Http::fake([
            'llm.test/api/v1/*' => Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => '01 is the weak frame; the rest are clean.']],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), [
                'trigger_id' => $this->createHumanTrigger('Please propose a cull for the weak frames.'),
            ])
            ->assertOk();

        $body = (string) $response->json('message.body');
        $this->assertStringContainsString('weak frame', $body);
        // The deterministic proposal path still ran under the LLM reply, and
        // the reply carries the approval pointer for the photographer.
        $this->assertMatchesRegularExpression('/cull proposal \(#\d+\) is waiting for your approval/', $body);

        $this->assertSame(1, $this->project->proposals()->where('type', Domain::TYPE_CULL)->count());
    }

    private function createHumanTrigger(string $body = 'Please review this project.'): int
    {
        return $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.store', $this->project), [
                'body' => $body,
                'client_message_id' => (string) Uuid::uuid4(),
            ])
            ->assertCreated()
            ->json('message.id');
    }
}
