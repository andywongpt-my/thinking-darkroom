<?php

namespace App\Services;

use App\Domain\Domain;
use App\Models\Photo;
use App\Models\Project;
use App\Models\User;
use App\Services\Culling\ContextAwareCullingService;
use App\Services\Media\MediaStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Grounded LLM reasoning for agent conversation turns.
 *
 * The LLM never sees free-form project state: the context builder serializes
 * ONLY persisted, auditable evidence (photo observations, QA findings,
 * proposal state, adopted concept). The model's job is cross-photo reasoning
 * — comparing frames, explaining tradeoffs, answering "why" questions — never
 * inventing observations or taking actions.
 *
 * Authority is unchanged: the turn remains ANALYZE-only. A structured
 * "suggest_cull" instruction from the model is surfaced as prose; actually
 * creating a proposal still goes through the deterministic keyword path or
 * the propose_cull WebMCP tool.
 */
class AgentLlmService
{
    /** Hard cap on context characters so the request stays fast and cheap. */
    private const MAX_CONTEXT_CHARS = 9000;

    /** Hard cap on reply characters stored in the conversation. */
    private const MAX_REPLY_CHARS = 2400;

    private const SYSTEM_PROMPT = <<<'TXT'
You are the WebMCP culling agent inside Thinking Darkroom, assisting a photographer with edit selection.

HARD RULES — violating any of these is a failure:
1. Use ONLY the JSON evidence provided. Never invent an observation, filename, assessment, or number. If the evidence does not answer the question, say so plainly.
2. You are an ANALYZE-stage assistant. NEVER claim to have changed, culled, selected, or applied anything. Selection authority is the photographer's alone — close decision-oriented replies with one short line reminding them of that.
3. Reference photos by their exact filenames from the evidence.
4. Be concrete: compare specific frames (e.g. "05 is softer than 02 across the burst, so 02 carries the moment"). No generic photography lectures.
5. Provenance honesty: technical assessments come from on-device pixel analysis with per-assessment confidence; creative fit is only meaningful against the adopted direction. Reflect low confidence as uncertainty instead of hiding it.
6. Reply in plain text, 180 words or fewer. No markdown headers, no bullet characters other than "-" at line starts.
TXT;

    /** OpenRouter free-tier model used when AGENT_LLM_MODEL is not set. */
    private const DEFAULT_MODEL = 'meta-llama/llama-3.3-70b-instruct:free';

    /**
     * Why the most recent reply() returned null (null when it succeeded or
     * the service was simply disabled). Lets the turn composer tell the
     * photographer WHY they are reading a deterministic summary instead of
     * AI reasoning — a silently swallowed provider failure hides broken keys.
     */
    private ?string $lastFailure = null;

    public function __construct(
        private readonly ContextAwareCullingService $culling,
        private readonly ToolCallAuditService $audit,
        private readonly Request $request,
    ) {}

    /**
     * Whether LLM reasoning is configured for this deployment. The service is
     * provider-agnostic (any OpenAI-compatible endpoint): set
     * AGENT_LLM_BASE_URL / AGENT_LLM_API_KEY / AGENT_LLM_MODEL. The default
     * base URL is OpenRouter's free-tier endpoint.
     */
    public function enabled(): bool
    {
        return (string) config('services.agent_llm.key', '') !== ''
            && (string) config('services.agent_llm.model', '') !== '';
    }

    /**
     * P2c — whether reasoning is available for THIS trigger's photographer:
     * their BYO key (encrypted DB) activates the LLM even when the
     * deployment env has none. Deployment env keeps working as-is.
     */
    public function enabledFor(?User $user): bool
    {
        if ($user !== null && $user->aiApiKey() !== null) {
            return true;
        }

        return $this->enabled();
    }

    /**
     * P2c — effective reasoning settings for the acting photographer. A BYO
     * key (encrypted DB) wins over deployment env; without any key the
     * service stays disabled and the caller falls back to the deterministic
     * composer. `source` lands in the audit ledger for honest provenance.
     *
     * @return array{key: string, model: string, base_url: string, source: string}|null
     */
    private function settingsFor(?User $user): ?array
    {
        if ($user !== null && $user->aiApiKey() !== null) {
            $ai = $user->effectiveAiSettings();

            return [
                'key' => $ai['key'],
                'model' => $ai['model'] !== '' ? $ai['model'] : self::DEFAULT_MODEL,
                'base_url' => $ai['base_url'],
                'source' => 'photographer_bring_your_own',
            ];
        }

        $key = (string) config('services.agent_llm.key', '');

        if ($key === '' || (string) config('services.agent_llm.model', '') === '') {
            return null;
        }

        return [
            'key' => $key,
            'model' => (string) (config('services.agent_llm.model') ?: self::DEFAULT_MODEL),
            'base_url' => (string) config('services.agent_llm.base_url', 'https://openrouter.ai/api/v1'),
            'source' => 'deployment_env',
        ];
    }

    /**
     * Produce one grounded LLM reply for a conversation turn. Returns null
     * when the LLM is disabled or fails — the caller falls back to the
     * deterministic composer so the turn never blocks on the model.
     *
     * Multimodal (P2b): when services.agent_llm.vision is enabled the user
     * message also carries downscaled inline images of the top candidate
     * frames (by recommendation confidence), so the model reasons over the
     * actual pixels IN ADDITION to the persisted evidence JSON. The images
     * are extra context, never extra authority: the JSON evidence stays the
     * only source of truth for claims.
     *
     * @param  array{photos: int, selected: int, culled: int, unreviewed: int, observations: int, qa_open: int, pending_proposals: int, adopted_brief_title: string|null, has_intent: bool, provenance: string, soft_frames: int}  $summary
     */
    public function reply(Project $project, string $message, array $summary, ?string $directionQuery, ?User $actingUser = null): ?string
    {
        $this->lastFailure = null;
        $settings = $this->settingsFor($actingUser);

        if ($settings === null) {
            $this->lastFailure = 'llm_not_configured';

            return null;
        }

        $context = $this->buildContext($project, $summary);
        $userPrompt = "Photographer's message: {$message}\n\n"
            ."Project evidence (JSON — your only source of truth):\n{$context}";

        $startedAt = hrtime(true);

        $userContent = [['type' => 'text', 'text' => $userPrompt]];

        // P2b — attach real thumbnails of the strongest candidate frames so
        // the model can compare actual pixels, not just labels.
        foreach ($this->topCandidateThumbnails($project, 4) as $thumb) {
            $userContent[] = ['type' => 'image_url', 'image_url' => [
                'url' => 'data:image/jpeg;base64,'.base64_encode($thumb['jpeg']),
            ]];
            $userContent[] = ['type' => 'text', 'text' => "Image: {$thumb['filename']} (photo #{$thumb['id']})"];
        }

        try {
            $response = Http::withToken($settings['key'])
                ->withHeaders([
                    // OpenRouter attribution headers (optional but recommended).
                    'HTTP-Referer' => (string) config('app.url'),
                    'X-Title' => 'Thinking Darkroom',
                ])
                ->timeout((int) config('services.agent_llm.timeout', 20))
                ->retry(1, 300)
                ->acceptJson()
                ->post(rtrim($settings['base_url'], '/').'/chat/completions', [
                    'model' => $settings['model'],
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        ['role' => 'user', 'content' => config('services.agent_llm.vision', true)
                            ? $userContent
                            : $userPrompt],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 700,
                ]);
        } catch (\Throwable $e) {
            Log::warning('agent_llm.request_failed', ['error' => $e->getMessage()]);
            $this->lastFailure = 'llm_request_failed';
            $this->recordFailureAudit($project, $settings, 'llm_request_failed', $startedAt);

            return null;
        }

        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

        if ($response->failed()) {
            Log::warning('agent_llm.http_error', ['status' => $response->status()]);
            $this->lastFailure = 'llm_http_'.$response->status();
            $this->recordFailureAudit($project, $settings, $this->lastFailure, $startedAt);

            return null;
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');
        $content = trim($content);

        if ($content === '') {
            $this->lastFailure = 'llm_empty_reply';
            $this->recordFailureAudit($project, $settings, 'llm_empty_reply', $startedAt);

            return null;
        }

        $content = mb_substr($content, 0, self::MAX_REPLY_CHARS);

        // The reasoning step is an ANALYZE tool call in the audit ledger, so
        // judges can see the model worked from persisted evidence.
        $this->audit->record(
            $this->request,
            $project,
            $this->agentMember($project),
            'llm_reasoning',
            Domain::AUTHORITY_ANALYZE,
            [
                'model' => $settings['model'],
                'context_chars' => mb_strlen($context),
                'direction_query' => $directionQuery,
                'settings_source' => $settings['source'],
            ],
            [
                'reply_chars' => mb_strlen($content),
                'http_status' => $response->status(),
            ],
            Domain::RESULT_COMPLETED,
            $durationMs,
        );

        return $content;
    }

    /**
     * Why the most recent reply() returned null (null on success or when the
     * service was never configured). AgentTurnService appends this to the
     * deterministic fallback reply so a broken BYO key is never silent.
     */
    public function lastFailure(): ?string
    {
        return $this->lastFailure;
    }

    /**
     * Record a FAILED reasoning attempt in the audit ledger. Mirrors the
     * success-path llm_reasoning entry with RESULT_ERROR so judges see that
     * reasoning was ATTEMPTED and why it produced no output — a silent
     * provider failure would otherwise look like "the agent chose not to".
     *
     * @param  array{key: string, model: string, base_url: string, source: string}  $settings
     */
    private function recordFailureAudit(Project $project, array $settings, string $reason, int $startedAt): void
    {
        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

        $this->audit->record(
            $this->request,
            $project,
            $this->agentMember($project),
            'llm_reasoning',
            Domain::AUTHORITY_ANALYZE,
            [
                'model' => $settings['model'],
                'settings_source' => $settings['source'],
            ],
            ['failure' => $reason],
            Domain::RESULT_ERROR,
            $durationMs,
        );
    }

    /**
     * Serialize ONLY persisted evidence into a compact JSON context for the
     * model. Includes per-photo observations, QA findings, proposal state,
     * similarity groups, and the adopted concept.
     *
     * @param  array{photos: int, selected: int, culled: int, unreviewed: int, observations: int, qa_open: int, pending_proposals: int, adopted_brief_title: string|null, has_intent: bool, provenance: string, soft_frames: int}  $summary
     */
    private function buildContext(Project $project, array $summary): string
    {
        $direction = $this->culling->contextSummary($project);
        $recommendations = $this->culling->recommendForProject($project);

        $photos = [];
        foreach ((array) ($recommendations['recommendations'] ?? []) as $rec) {
            $photo = (array) ($rec['photo'] ?? []);

            $photos[] = [
                'id' => $photo['id'] ?? null,
                'filename' => $photo['original_name'] ?? $photo['filename'] ?? null,
                'selection_state' => $photo['selection_state'] ?? null,
                'deterministic_recommendation' => $rec['recommendation'] ?? null,
                'confidence' => isset($rec['confidence']) ? round((float) $rec['confidence'], 2) : null,
                'technical' => $rec['technical_rationale'] ?? null,
                'creative_fit' => $rec['creative_rationale'] ?? null,
                'tradeoff' => $rec['tradeoff'] ?? null,
                'similarity_group' => $rec['similarity_group'] ?? null,
                'group_size' => $rec['similarity_group_size'] ?? 0,
            ];
        }

        $findings = $project->findings()
            ->orderByDesc('id')
            ->limit(12)
            ->get(['id', 'photo_id', 'severity', 'category', 'message', 'status'])
            ->map(fn ($f) => [
                'photo_id' => $f->photo_id,
                'severity' => $f->severity,
                'category' => $f->category,
                'message' => $f->message,
                'status' => $f->status,
            ])
            ->all();

        $proposals = $project->proposals()
            ->orderByDesc('id')
            ->limit(6)
            ->get(['id', 'type', 'status', 'summary'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'type' => $p->type,
                'status' => $p->status,
                'summary' => $p->summary,
            ])
            ->all();

        $payload = [
            'workspace' => [
                'photos' => $summary['photos'],
                'selected' => $summary['selected'],
                'culled' => $summary['culled'],
                'unreviewed' => $summary['unreviewed'],
                'qa_open' => $summary['qa_open'],
                'pending_proposals' => $summary['pending_proposals'],
            ],
            'creative_direction' => [
                'adopted_concept' => $direction['adopted_concept'] ?? null,
                'selection_priority' => $direction['selection_priority'] ?? null,
                'has_direction' => (bool) ($direction['has_direction'] ?? false),
            ],
            'similarity_groups' => $direction['duplicate_groups'] ?? [],
            'photos' => $photos,
            'qa_findings' => $findings,
            'proposals' => $proposals,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json !== false && mb_strlen($json) > self::MAX_CONTEXT_CHARS) {
            // Keep the newest evidence; drop photo detail breadth first.
            $payload['photos'] = array_slice($payload['photos'], 0, max(4, (int) (count($payload['photos']) * 0.6)));
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $json !== false ? $json : '{}';
    }

    private function agentMember(Project $project): User
    {
        $agent = $project->members()
            ->wherePivot('role', Domain::ROLE_AGENT)
            ->where('users.is_agent', true)
            ->orderBy('users.id')
            ->first();

        // The turn path guarantees an agent member exists; this defensive
        // fallback keeps the audit write total if a race removed membership.
        /** @var User */
        return $agent ?? User::query()->where('is_agent', true)->orderBy('id')->firstOrFail();
    }

    /**
     * P2b — downscaled JPEG thumbnails (longest edge 384, quality 72) of the
     * top candidate frames, strongest recommendation confidence first. The
     * model SEES these alongside the evidence JSON. Returns [] when GD is
     * unavailable or no photo bytes are readable — vision context degrades
     * to text-only, never blocks the turn.
     *
     * @return list<array{id: int, filename: string, jpeg: string}>
     */
    private function topCandidateThumbnails(Project $project, int $limit): array
    {
        if (! config('services.agent_llm.vision', true) || ! function_exists('imagecreatefromstring')) {
            return [];
        }

        $recommendations = $this->culling->recommendForProject($project)['recommendations'] ?? [];

        usort($recommendations, fn (array $a, array $b) => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));

        $thumbs = [];
        $media = app(MediaStore::class);

        foreach (array_slice($recommendations, 0, $limit) as $rec) {
            $photoData = (array) ($rec['photo'] ?? []);
            $photoId = $photoData['id'] ?? null;

            if ($photoId === null) {
                continue;
            }

            $photo = Photo::find($photoId);

            if ($photo === null || ! $photo->path) {
                continue;
            }

            try {
                $bytes = $media->read($photo->path);
            } catch (\Throwable) {
                continue;
            }

            if ($bytes === '') {
                continue;
            }

            $image = @imagecreatefromstring($bytes);

            if ($image === false) {
                continue;
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1.0, 384 / max($width, $height));
            $small = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            imagecopyresampled($small, $image, 0, 0, 0, 0, imagesx($small), imagesy($small), $width, $height);

            ob_start();
            imagejpeg($small, null, 72);
            $jpeg = (string) ob_get_clean();
            imagedestroy($small);
            imagedestroy($image);

            if ($jpeg !== '') {
                $thumbs[] = [
                    'id' => (int) $photoId,
                    'filename' => (string) ($photoData['original_name'] ?? $photoData['filename'] ?? "photo #{$photoId}"),
                    'jpeg' => $jpeg,
                ];
            }
        }

        return $thumbs;
    }
}
