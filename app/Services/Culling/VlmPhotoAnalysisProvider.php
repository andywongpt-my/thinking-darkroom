<?php

namespace App\Services\Culling;

use App\Domain\Culling\PhotoObservation;
use App\Domain\Domain;
use App\Models\Photo;
use App\Models\User;
use App\Services\Media\MediaStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production VLM photo-analysis provider — OpenAI-compatible vision model
 * via OpenRouter (one key drives both photo analysis and agent reasoning).
 *
 * What it REALLY does: sends the stored image bytes (base64 inline data,
 * downscaled to keep serverless payloads small) to a vision-language model
 * and asks for a STRICT JSON observation in the exact PhotoObservation
 * shape (technical + creative sections). The reply is validated
 * server-side against allow-lists — anything the model invents (unknown
 * keys, out-of-enum assessments, non-numeric confidence) is coerced to
 * "unknown", never trusted. The model's text is data, not authority.
 *
 * Honest provenance: every observation row records
 * Domain::OBSERVATION_PROVIDER_VLM plus 'external_vision_model (<model>)',
 * so the workspace UI attributes analysis truthfully.
 *
 * Failure behaviour: network/timeout/parse failures NEVER throw into the
 * analysis pipeline — observe() degrades to the deterministic GD
 * pixel-statistics provider (its own honest provenance) so analysis never
 * blocks on the model. Similarity grouping is likewise delegated to the
 * GD perceptual-hash implementation either way.
 *
 * Sprint 3 rule still enforced: observations are DATA, not decisions — this
 * provider never touches selection_state.
 */
class VlmPhotoAnalysisProvider implements PhotoAnalysisProvider
{
    /** Downscaled analysis size (longest edge) — keeps the VLM payload small. */
    private const ANALYSIS_EDGE = 768;

    private const PROMPT = <<<'TXT'
        You are a strict photographic image-analysis engine inside a professional
        culling workflow. Analyze the attached photograph and respond with ONLY
        a JSON object — no markdown fences, no prose — matching exactly:

        {
          "technical": {
            "sharpness":            { "assessment": "sharp|slightly_soft|soft|unknown", "confidence": 0.0-1.0 },
            "exposure":             { "assessment": "underexposed|acceptable|overexposed|unknown", "confidence": 0.0-1.0 },
            "motion_blur":          { "assessment": "none|mild|strong|unknown", "confidence": 0.0-1.0 },
            "highlight_clipping":   { "assessment": "safe|risk|unknown", "confidence": 0.0-1.0 },
            "eyes_open":            { "assessment": "open|partial|closed|unknown", "confidence": 0.0-1.0 }
          },
          "creative": {
            "expression":                 "genuine|neutral|forced|strained|unknown",
            "candidness":                 "candid|posed|semi_posed|unknown",
            "environmental_storytelling": "strong|moderate|weak|unknown",
            "mood":                       ["max 3 lowercase mood words"],
            "compositional_fit":          "strong|adequate|weak|unknown",
            "emotion_strength":           "strong|moderate|weak|unknown"
          }
        }

        Rules:
        - Judge ONLY what is visible. No visible faces → eyes_open "unknown"
          and judge candidness from overall body language / scene context.
        - confidence is your honest certainty for that single assessment
          (0.0 = guessing, 1.0 = certain). Never inflate it.
        - "mood": lowercase, at most 3, free-form single words.
        - Never invent keys. Never add commentary. JSON only.
        TXT;

    /** Server-side allow-lists — the model's text is validated, never trusted.
     *
     * @var array<string, list<string>>
     */
    private const TECHNICAL_ENUMS = [
        'sharpness' => ['sharp', 'slightly_soft', 'soft', 'unknown'],
        'exposure' => ['underexposed', 'acceptable', 'overexposed', 'unknown'],
        'motion_blur' => ['none', 'mild', 'strong', 'unknown'],
        'highlight_clipping' => ['safe', 'risk', 'unknown'],
        'eyes_open' => ['open', 'partial', 'closed', 'unknown'],
    ];

    /** @var array<string, list<string>> */
    private const CREATIVE_ENUMS = [
        'expression' => ['genuine', 'neutral', 'forced', 'strained', 'unknown'],
        'candidness' => ['candid', 'posed', 'semi_posed', 'unknown'],
        'environmental_storytelling' => ['strong', 'moderate', 'weak', 'unknown'],
        'compositional_fit' => ['strong', 'adequate', 'weak', 'unknown'],
        'emotion_strength' => ['strong', 'moderate', 'weak', 'unknown'],
    ];

    public function __construct(
        private readonly DemoPhotoAnalysisProvider $fallback = new DemoPhotoAnalysisProvider,
    ) {}

    public function name(): string
    {
        return Domain::OBSERVATION_PROVIDER_VLM;
    }

    public function provenance(): string
    {
        return self::PROVENANCE_PREFIX.' ('.$this->modelId(null).')';
    }

    /** Provenance prefix shared by all VLM observations. */
    public const PROVENANCE_PREFIX = 'external_vision_model';

    public function observe(Photo $photo): PhotoObservation
    {
        return $this->observeAs(null, $photo);
    }

    /**
     * P2c — observe with the acting user's BYO settings. User settings
     * (encrypted DB) override deployment env; no state is kept on this
     * singleton between calls.
     */
    public function observeAs(?User $user, Photo $photo): PhotoObservation
    {
        $settings = $this->settingsFor($user);

        if ($settings['key'] === '' || $settings['model'] === '') {
            return $this->fallback->observe($photo);
        }

        $bytes = $this->analysisBytes($photo);

        if ($bytes === null) {
            // No readable pixels → GD fallback also cannot analyze, but its
            // honest "unknown" shape is the correct degradation either way.
            return $this->fallback->observe($photo);
        }

        $startedAt = hrtime(true);

        try {
            $response = Http::withToken($settings['key'])
                ->timeout((int) config('services.vlm.timeout', 25))
                ->retry(1, 400)
                ->acceptJson()
                ->post(rtrim($settings['base_url'], '/').'/chat/completions', [
                    'model' => $settings['model'],
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'text', 'text' => self::PROMPT],
                                ['type' => 'image_url', 'image_url' => [
                                    'url' => 'data:'.($photo->mime ?: 'image/jpeg').';base64,'.base64_encode($bytes),
                                ]],
                            ],
                        ],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 700,
                    // Most OpenAI-compatible gateways honor this for JSON mode.
                    'response_format' => ['type' => 'json_object'],
                ]);
        } catch (Throwable $e) {
            Log::warning('vlm.request_failed', ['photo' => $photo->id, 'error' => $e->getMessage()]);

            return $this->fallback->observe($photo);
        }

        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

        if ($response->failed()) {
            Log::warning('vlm.http_error', ['photo' => $photo->id, 'status' => $response->status()]);

            return $this->fallback->observe($photo);
        }

        $decoded = $this->decodeModelJson((string) data_get($response->json(), 'choices.0.message.content', ''));

        if ($decoded === null) {
            Log::warning('vlm.unparseable_response', ['photo' => $photo->id]);

            return $this->fallback->observe($photo);
        }

        // Server-side authority: validate + coerce the model's JSON against
        // the PhotoObservation contract before it ever becomes evidence.
        $technical = [];
        foreach (self::TECHNICAL_ENUMS as $key => $allowed) {
            $technical[$key] = $this->coerceAssessment($decoded['technical'][$key] ?? null, $allowed);
        }

        $creative = [];
        foreach (self::CREATIVE_ENUMS as $key => $allowed) {
            $creative[$key] = $this->coerceCreative($decoded['creative'][$key] ?? null, $allowed);
        }
        $creative['mood'] = $this->coerceMood($decoded['creative']['mood'] ?? null);

        Log::info('vlm.observed', [
            'photo' => $photo->id,
            'model' => $settings['model'],
            'duration_ms' => (int) $durationMs,
        ]);

        // Similarity grouping stays deterministic on-device (perceptual
        // hash) — never delegated to a nondeterministic model.
        $similarityGroup = $this->fallback->similarityGroupFor($photo);

        return PhotoObservation::fromArray(
            $photo->id,
            ['technical' => $technical, 'creative' => $creative],
            $this->name(),
            self::PROVENANCE_PREFIX.' ('.$settings['model'].')',
            $similarityGroup,
        );
    }

    /* -------------------------------- validation -------------------------------- */

    /**
     * Parse the model's reply text, tolerating ```json fences some models
     * add despite instructions.
     *
     * @return array<string, mixed>|null
     */
    private function decodeModelJson(string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $m) === 1) {
            $text = $m[1];
        }

        // Some models emit leading prose before the JSON object; find the
        // first '{' and the last '}' and try that slice.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<string>  $allowed
     * @return array{assessment: string, confidence: float}
     */
    private function coerceAssessment(mixed $raw, array $allowed): array
    {
        if (! is_array($raw)) {
            return ['assessment' => 'unknown', 'confidence' => 0.0];
        }

        $assessment = mb_strtolower((string) ($raw['assessment'] ?? 'unknown'));

        if (! in_array($assessment, $allowed, true)) {
            return ['assessment' => 'unknown', 'confidence' => 0.0];
        }

        return ['assessment' => $assessment, 'confidence' => $this->coerceConfidence($raw['confidence'] ?? null)];
    }

    /** @param list<string> $allowed */
    private function coerceCreative(mixed $raw, array $allowed): string
    {
        $value = is_string($raw) ? mb_strtolower(trim($raw)) : 'unknown';

        return in_array($value, $allowed, true) ? $value : 'unknown';
    }

    /** @return list<string> */
    private function coerceMood(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $mood = [];
        foreach ($raw as $word) {
            if (is_string($word) && preg_match('/^[\pL\pN_-]{2,24}$/u', trim($word)) === 1) {
                $mood[] = mb_strtolower(trim($word));
            }
        }

        return array_values(array_slice(array_unique($mood), 0, 3));
    }

    private function coerceConfidence(mixed $raw): float
    {
        if (is_bool($raw) || ! is_numeric($raw)) {
            return 0.5;
        }

        return round(max(0.0, min(1.0, (float) $raw)), 2);
    }

    /* --------------------------------- helpers --------------------------------- */

    public function enabled(): bool
    {
        return $this->apiKey(null) !== '' && $this->modelId(null) !== '';
    }

    /**
     * P2c — per-user BYO settings (DB, encrypted) override deployment env.
     * Passed as an argument — the provider is a singleton and must never
     * carry user state between requests.
     *
     * @return array{key: string, model: string, base_url: string}
     */
    public function settingsFor(?User $user): array
    {
        if ($user !== null) {
            $settings = $user->effectiveAiSettings();
            $settings['key'] = $user->aiApiKey() ?? (string) config('services.vlm.key', '');

            return $settings;
        }

        return [
            'key' => (string) config('services.vlm.key', ''),
            'model' => (string) (config('services.vlm.model') ?: 'google/gemini-2.5-flash'),
            'base_url' => (string) (config('services.vlm.base_url') ?: 'https://openrouter.ai/api/v1'),
        ];
    }

    private function apiKey(?User $user): string
    {
        return $this->settingsFor($user)['key'];
    }

    private function modelId(?User $user): string
    {
        return $this->settingsFor($user)['model'];
    }

    private function baseUrl(?User $user): string
    {
        return $this->settingsFor($user)['base_url'];
    }

    /**
     * Downscale the stored image for analysis (longest edge ANALYSIS_EDGE,
     * JPEG q80) so the VLM payload stays small and the call fast. Falls back
     * to the raw bytes when GD is unavailable or the bytes are not decodable;
     * returns null only when there is nothing readable at all.
     */
    private function analysisBytes(Photo $photo): ?string
    {
        if (! $photo->path) {
            return null;
        }

        try {
            $bytes = app(MediaStore::class)->read($photo->path);
        } catch (Throwable) {
            return null;
        }

        if ($bytes === '') {
            return null;
        }

        // PNG/WebP originals: normalize to JPEG for a single VLM mime path.
        $mime = (string) ($photo->mime ?: 'image/jpeg');

        if (! function_exists('imagecreatefromstring')) {
            return $mime === 'image/jpeg' ? $bytes : null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return $mime === 'image/jpeg' ? $bytes : null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, self::ANALYSIS_EDGE / max($width, $height));
        $outW = max(1, (int) round($width * $scale));
        $outH = max(1, (int) round($height * $scale));
        $small = imagecreatetruecolor($outW, $outH);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $outW, $outH, $width, $height);

        ob_start();
        imagejpeg($small, null, 80);
        $jpeg = (string) ob_get_clean();
        imagedestroy($small);
        imagedestroy($image);

        return $jpeg === '' ? null : $jpeg;
    }
}
