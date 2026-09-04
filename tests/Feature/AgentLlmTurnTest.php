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
use Illuminate\Support\Facades\Storage;
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

        // Real JPEG bytes behind every photo path so the P2b multimodal
        // thumbnail builder can produce image parts (otherwise thumbnails
        // are empty and no vision request shape is ever exercised).
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor(64, 48);
            imagefilledrectangle($img, 0, 0, 63, 47, imagecolorallocate($img, 120, 120, 120));
            ob_start();
            imagejpeg($img, null, 80);
            $jpeg = (string) ob_get_clean();
            imagedestroy($img);

            foreach ($this->project->photos()->get() as $photo) {
                Storage::disk('public')->put($photo->path, $jpeg);
            }
        }
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
                'client_opt_in' => true,
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
        // Multimodal turns: the user content is a parts ARRAY whose first
        // part is the text; the thumbnail image parts follow when photos
        // are readable. Both shapes must carry the evidence text.
        Http::assertSent(function ($request) {
            $payload = $request->data();

            $userContent = $payload['messages'][1]['content'];
            $userText = is_string($userContent)
                ? $userContent
                : collect($userContent)->where('type', 'text')->pluck('text')->implode("\n");

            return str_contains((string) $payload['messages'][0]['content'], 'NEVER claim to have changed')
                && str_contains($userText, '"photos"')
                && str_contains($userText, 'deterministic_recommendation');
        });
    }

    public function test_provider_failure_falls_back_to_the_deterministic_composer(): void
    {
        Http::fake([
            'llm.test/api/v1/*' => Http::response(['error' => ['code' => 503]], 503),
        ]);

        $response = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), [
                'client_opt_in' => true,
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
                'client_opt_in' => true,
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
        $payload = [
            'trigger_id' => $triggerId,
            'client_opt_in' => true,
        ];

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
                'client_opt_in' => true,
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

    /**
     * P2c — a photographer's BYO key activates LLM reasoning even when the
     * deployment env has none. The BYO credentials hit their preset
     * endpoint; the audit ledger honestly records the settings source.
     */
    public function test_photographer_bring_your_own_key_activates_reasoning_without_env_key(): void
    {
        config([
            'services.agent_llm.key' => null,
            'services.agent_llm.model' => null,
            'services.agent_llm.base_url' => 'https://llm.test/api/v1',
        ]);

        $this->photographer->setAiApiKey('sk-or-v1-byoooooooooooooooooooooooooooooooooooooooo');
        $this->photographer->refresh();

        $service = app(AgentLlmService::class);
        $this->assertFalse($service->enabled());
        $this->assertTrue($service->enabledFor($this->photographer));

        // BYO settings resolve to the photographer's provider preset
        // (OpenRouter) because no base_url override is stored — fake that
        // endpoint, not the deployment env one.
        Http::fake([
            'openrouter.ai/api/v1/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Compared against the evidence, frame 02 carries the moment; the photographer decides.',
                    ],
                ]],
            ], 200),
        ]);

        $response = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), [
                'client_opt_in' => true,
                'trigger_id' => $this->createHumanTrigger('Which frames carry this burst, and why?'),
            ])
            ->assertOk();

        $body = (string) $response->json('message.body');
        $this->assertStringContainsString('frame 02', $body);

        // The BYO request used the decrypted key and the OpenRouter preset
        // endpoint (no user base_url override stored).
        Http::assertSent(function ($request) {
            return str_contains((string) ($request->header('Authorization')[0] ?? ''), 'sk-or-v1-byo')
                && str_starts_with($request->url(), 'https://openrouter.ai/api/v1/chat/completions');
        });

        // Honest provenance in the audit ledger.
        $audit = AgentToolCall::query()
            ->where('project_id', $this->project->id)
            ->where('tool_name', 'llm_reasoning')
            ->firstOrFail();
        $this->assertSame('photographer_bring_your_own', $audit->input['settings_source'] ?? null);
    }

    public function test_env_settings_still_apply_when_photographer_has_no_own_key(): void
    {
        config([
            'services.agent_llm.key' => 'env-key',
            'services.agent_llm.model' => 'env-model:free',
            'services.agent_llm.base_url' => 'https://llm.test/api/v1',
        ]);

        Http::fake([
            'llm.test/api/v1/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Deterministic evidence says frame 02 is strongest.'],
                ]],
            ], 200),
        ]);

        $response = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), [
                'client_opt_in' => true,
                'trigger_id' => $this->createHumanTrigger('Which frames stand out?'),
            ])
            ->assertOk();

        $this->assertStringContainsString('frame 02', (string) $response->json('message.body'));

        Http::assertSent(function ($request) {
            return str_contains((string) $request->header('Authorization')[0] ?? '', 'env-key');
        });

        $audit = AgentToolCall::query()
            ->where('project_id', $this->project->id)
            ->where('tool_name', 'llm_reasoning')
            ->firstOrFail();
        $this->assertSame('deployment_env', $audit->input['settings_source'] ?? null);
    }

    /**
     * 2026-09-04 AGY LOW — self-healing multimodal degradation: a text-only
     * bound model rejects image parts with HTTP 400. The SAME turn must be
     * retried over the evidence JSON alone (reasoning survives), and the
     * model must be remembered text-only so later turns send a single
     * text-only request instead of repeating the doomed multimodal one.
     */
    public function test_text_only_model_400_degrades_to_text_and_is_remembered(): void
    {
        // First call (multimodal) → 400; retry (text-only) → 200; the
        // remembered text-only flag makes the second turn text-only too.
        Http::fake([
            'llm.test/api/v1/*' => Http::sequence()
                ->push(['error' => ['message' => 'model does not support image parts']], 400)
                ->push([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Frame 02 reads strongest from the evidence.']]],
                ])
                ->push([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Second turn replies from evidence too.']]],
                ]),
        ]);

        $first = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), [
                'client_opt_in' => true,
                'trigger_id' => $this->createHumanTrigger('Which frame stands out?'),
            ])
            ->assertOk();

        $this->assertStringContainsString('Frame 02', (string) $first->json('message.body'));

        // The recovered request must have been the text-only retry.
        Http::assertSent(function ($request) {
            $payload = $request->data();
            $content = $payload['messages'][1]['content'];

            return is_string($content) && str_contains($content, '"photos"');
        });

        // Second turn: no multimodal attempt at all — exactly one request.
        $second = $this->actingAs($this->photographer)
            ->postJson(route('agent-conversation.turn', $this->project), [
                'client_opt_in' => true,
                'trigger_id' => $this->createHumanTrigger('And now?'),
            ])
            ->assertOk();

        $this->assertStringContainsString('Second turn', (string) $second->json('message.body'));
        $this->assertSame(3, count(Http::recorded()), 'turn 1 = 400 + retry; turn 2 = single text-only request');
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
