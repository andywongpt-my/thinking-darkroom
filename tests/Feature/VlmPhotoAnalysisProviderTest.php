<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\Photo;
use App\Models\Project;
use App\Models\User;
use App\Services\Culling\VlmPhotoAnalysisProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P1 — the production VLM photo-analysis provider: strict server-side
 * validation of the model's JSON, honest provenance, and the never-block
 * fallback chain to the deterministic GD provider.
 */
class VlmPhotoAnalysisProviderTest extends TestCase
{
    use RefreshDatabase;

    private VlmPhotoAnalysisProvider $provider;

    private Photo $photo;

    protected function setUp(): void
    {
        parent::setUp();

        // A real, decodable JPEG so the GD paths run for real.
        $img = imagecreatetruecolor(64, 48);
        imagefilledrectangle($img, 0, 0, 63, 47, imagecolorallocate($img, 120, 120, 120));
        $tmp = sys_get_temp_dir().'/vlm-test-'.getmypid().'.jpg';
        imagejpeg($img, $tmp, 90);
        imagedestroy($img);

        $user = User::query()->where('is_agent', true)->first()
            ?? User::factory()->create(['is_agent' => true, 'email' => 'agent@webmcp.test']);
        $photographer = User::factory()->create(['email' => 'photographer@webmcp.test']);
        $project = Project::create([
            'name' => 'VLM Test Project',
            'owner_id' => $photographer->id,
        ]);
        $project->members()->attach([$photographer->id => ['role' => 'owner'], $user->id => ['role' => 'agent']]);

        $this->photo = Photo::create([
            'project_id' => $project->id,
            'filename' => 'vlm-test.jpg',
            'original_name' => 'vlm-test.jpg',
            'path' => 'vlm-test/vlm-test.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => (int) filesize($tmp),
            'width' => 64,
            'height' => 48,
            'selection_state' => Domain::SELECTION_UNREVIEWED,
            'retouch_state' => Domain::RETOUCH_NONE,
        ]);

        // Real bytes behind the path (local disk MediaStore).
        if (! is_dir(storage_path('app/public/vlm-test'))) {
            mkdir(storage_path('app/public/vlm-test'), 0775, true);
        }
        file_put_contents(storage_path('app/public/vlm-test/vlm-test.jpg'), file_get_contents($tmp));

        $this->provider = app(VlmPhotoAnalysisProvider::class);
    }

    public function test_no_key_delegates_to_deterministic_fallback(): void
    {
        config(['services.vlm.key' => null]);

        $observation = $this->provider->observe($this->photo);

        // The GD fallback provider runs honestly and declares itself.
        $this->assertSame(Domain::OBSERVATION_PROVIDER_DEMO, $observation->provider);
        $this->assertNotSame(Domain::OBSERVATION_PROVIDER_VLM, $observation->provider);
        Http::assertNothingSent();
    }

    public function test_valid_model_json_becomes_validated_vlm_evidence(): void
    {
        config(['services.vlm.key' => 'test-key', 'services.vlm.model' => 'test-vision-model']);

        Http::fake([
            'openrouter.ai/api/v1/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode([
                            'technical' => [
                                'sharpness' => ['assessment' => 'sharp', 'confidence' => 0.9],
                                'exposure' => ['assessment' => 'underexposed', 'confidence' => 0.8],
                                'motion_blur' => ['assessment' => 'none', 'confidence' => 0.7],
                                'highlight_clipping' => ['assessment' => 'safe', 'confidence' => 0.85],
                                'eyes_open' => ['assessment' => 'open', 'confidence' => 0.6],
                            ],
                            'creative' => [
                                'expression' => 'genuine',
                                'candidness' => 'candid',
                                'environmental_storytelling' => 'strong',
                                'mood' => ['warm', 'serene', 'joyful'],
                                'compositional_fit' => 'strong',
                                'emotion_strength' => 'strong',
                            ],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $observation = $this->provider->observe($this->photo);

        $this->assertSame(Domain::OBSERVATION_PROVIDER_VLM, $observation->provider);
        $this->assertStringContainsString('external_vision_model', $observation->provenance);
        $this->assertStringContainsString('test-vision-model', $observation->provenance);

        $this->assertSame('sharp', $observation->sharpness()['assessment']);
        $this->assertSame('underexposed', $observation->exposure()['assessment']);
        $this->assertSame('open', $observation->eyesOpen()['assessment']);
        $this->assertSame('genuine', $observation->expression());
        $this->assertSame(['warm', 'serene', 'joyful'], $observation->mood());

        // The request must have carried the image inline (base64 JPEG data).
        Http::assertSent(function ($request) {
            $body = collect($request->data()['messages'][0]['content'] ?? []);

            return $body->contains(fn ($part) => ($part['type'] ?? null) === 'image_url'
                && str_starts_with($part['image_url']['url'] ?? '', 'data:image/'));
        });
    }

    public function test_invented_keys_and_out_of_enum_values_are_coerced_to_unknown(): void
    {
        config(['services.vlm.key' => 'test-key']);

        Http::fake([
            'openrouter.ai/api/v1/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode([
                            'technical' => [
                                'sharpness' => ['assessment' => 'ultra_sharp', 'confidence' => 0.99],  // invented enum
                                'exposure' => ['assessment' => 'acceptable', 'confidence' => 'high'],  // non-numeric confidence
                                'motion_blur' => 'missing',                                               // wrong shape
                                'highlight_clipping' => ['assessment' => 'safe', 'confidence' => 5],   // out-of-range confidence
                                'eyes_open' => ['assessment' => 'closed', 'confidence' => 0.5],
                                'hallucinated_dimension' => ['assessment' => 'x', 'confidence' => 1],   // invented key
                            ],
                            'creative' => [
                                'expression' => 'SUPER HAPPY',       // not in enum
                                'candidness' => 'candid',
                                'mood' => ['calm', 'stormy weather is nice', 42, 'ok'], // garbage filtered
                                'compositional_fit' => 'strong',
                            ],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $observation = $this->provider->observe($this->photo);

        $this->assertSame(Domain::OBSERVATION_PROVIDER_VLM, $observation->provider);

        // Out-of-enum → unknown at zero confidence; server never trusts model text.
        $this->assertSame('unknown', $observation->sharpness()['assessment']);
        $this->assertSame(0.0, $observation->sharpness()['confidence']);

        // Non-numeric confidence → honest default 0.5.
        $this->assertSame(0.5, $observation->exposure()['confidence']);

        // Wrong shape → unknown.
        $this->assertSame('unknown', $observation->motionBlur()['assessment']);

        // Out-of-range confidence clamps to 1.0.
        $this->assertSame(1.0, $observation->highlightClipping()['confidence']);

        // Unknown technical key simply never appears in the payload.
        $this->assertArrayNotHasKey('hallucinated_dimension', $observation->toArray()['technical']);

        // Creative enum miss → unknown.
        $this->assertSame('unknown', $observation->expression());

        // Mood: over-length garbage dropped, keeps ≤3 clean words.
        $this->assertSame(['calm', 'ok'], $observation->mood());
    }

    public function test_http_failure_falls_back_to_gd_provider(): void
    {
        config(['services.vlm.key' => 'test-key']);

        Http::fake([
            'openrouter.ai/api/v1/*' => Http::response(['error' => 'boom'], 500),
        ]);

        $observation = $this->provider->observe($this->photo);

        $this->assertSame(Domain::OBSERVATION_PROVIDER_DEMO, $observation->provider);
        $this->assertSame(
            Domain::OBSERVATION_PROVENANCES[Domain::OBSERVATION_PROVIDER_DEMO],
            $observation->provenance,
        );
    }

    public function test_unparseable_model_reply_falls_back_to_gd_provider(): void
    {
        config(['services.vlm.key' => 'test-key']);

        Http::fake([
            'openrouter.ai/api/v1/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'I really like this photo! It is great.'],
                ]],
            ], 200),
        ]);

        $observation = $this->provider->observe($this->photo);

        $this->assertSame(Domain::OBSERVATION_PROVIDER_DEMO, $observation->provider);
    }

    public function test_json_fenced_reply_is_parsed(): void
    {
        config(['services.vlm.key' => 'test-key']);

        $payload = json_encode([
            'technical' => [
                'sharpness' => ['assessment' => 'sharp', 'confidence' => 0.9],
                'exposure' => ['assessment' => 'acceptable', 'confidence' => 0.9],
                'motion_blur' => ['assessment' => 'none', 'confidence' => 0.9],
                'highlight_clipping' => ['assessment' => 'safe', 'confidence' => 0.9],
            ],
            'creative' => [
                'expression' => 'neutral',
                'candidness' => 'semi_posed',
                'mood' => ['neutral'],
            ],
        ]);

        Http::fake([
            'openrouter.ai/api/v1/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => "Here is my analysis:\n```json\n{$payload}\n```\nHope that helps!"],
                ]],
            ], 200),
        ]);

        $observation = $this->provider->observe($this->photo);

        $this->assertSame(Domain::OBSERVATION_PROVIDER_VLM, $observation->provider);
        $this->assertSame('sharp', $observation->sharpness()['assessment']);
        $this->assertSame(['neutral'], $observation->mood());
    }
}
