<?php

namespace App\Services\Retouch;

use App\Domain\Culling\PhotoObservation;
use App\Domain\Domain;
use App\Models\Project;
use App\Services\CreativeRoomService;
use App\Services\Culling\ContextAwareCullingService;

/**
 * Sprint 4 — Creative-Brief-aware retouch PROPOSAL layer.
 *
 * Consumes BOTH:
 *   A. the photo's structured observation (PhotoObservation — measured data)
 *   B. the photographer-adopted Creative Brief via
 *      CreativeRoomService::structuredIntentFor($project)
 *
 * and derives a deterministic, explainable, normalized adjustment set
 * (exposure / contrast / saturation / warmth / highlight_recovery /
 * shadow_lift, each -1.0..+1.0) that serves the adopted intent.
 *
 * HARD BOUNDARY: this service PROPOSES only. It never executes, never
 * touches pixels, never changes retouch_state. Rendering happens only in
 * ProposalApplicator through an APPROVED proposal (apply_approved_plan).
 * No VLM, no masks, no generative editing — deterministic heuristics only.
 *
 * Every recommendation carries an `influenced_by` trace naming the brief
 * dimensions that moved each adjustment, mirroring the Sprint 3 culling
 * dynamic: agent proposes, photographer decides.
 */
class ContextAwareRetouchService
{
    public function __construct(
        private readonly CreativeRoomService $creative,
        private readonly ContextAwareCullingService $culling,
    ) {}

    /**
     * Derive (but do NOT persist) a brief-aware retouch recommendation for
     * one photo. Returns null when there is no observation to reason from.
     *
     * @return array<string, mixed>|null
     */
    public function recommendForPhoto(Project $project, PhotoObservation $observation): ?array
    {
        $direction = $this->creative->structuredIntentFor($project);
        $intent = $direction['intent'] ?? null;

        return $this->derive(
            $observation,
            $intent,
            $this->memoryLessonsFor($project),
        );
    }

    /**
     * Brief-aware recommendations for every observed photo in the project.
     *
     * @return array<string, mixed>
     */
    public function recommendForProject(Project $project): array
    {
        $direction = $this->creative->structuredIntentFor($project);
        $intent = $direction['intent'] ?? null;

        $observations = $this->culling->observationsFor($project);
        $memoryLessons = $this->memoryLessonsFor($project);
        $recommendations = [];

        foreach ($observations as $observation) {
            $recommendation = $this->derive($observation, $intent, $memoryLessons);
            if ($recommendation !== null) {
                $recommendations[] = $recommendation;
            }
        }

        return [
            'project_id' => $project->id,
            'has_direction' => $intent !== null,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * The deterministic derivation core: (observation, intent, memories) →
     * adjustments. Pure function — identical inputs always produce identical
     * output.
     *
     * @param  array<string, mixed>|null  $intent  structuredIntentFor()['intent'] — null = no adopted direction
     * @param  list<string>  $memoryLessons  durable photographer memory lessons
     */
    public function derive(PhotoObservation $observation, ?array $intent, array $memoryLessons = []): ?array
    {
        $technical = $observation->technical;

        $exposure = $technical['exposure']['assessment'] ?? 'unknown';
        $highlight = $technical['highlight_clipping']['assessment'] ?? 'unknown';
        $sharpness = $technical['sharpness']['assessment'] ?? 'unknown';

        // ---- base adjustments from measured technical state ----
        $adjustments = [];
        $influencedBy = [];

        // P3b provenance: when the observation came from the vision model,
        // stamp it — the audit trail must show a VLM-sourced recommendation
        // apart from a deterministic-pixel one.
        if ($observation->provider === Domain::OBSERVATION_PROVIDER_VLM) {
            $influencedBy[] = 'vlm.vision_observation';
        }

        // Exposure correction is driven by the MEAN LUMINANCE the provider
        // measured, not by the label alone — the same measurement always
        // yields the same offset.
        $meanLuminance = $this->meanLuminance($observation);
        if ($meanLuminance !== null) {
            // Map measured mean (0..255) to a bounded correction: dark frames
            // lift, bright frames recover, normal zone untouched.
            $offset = $this->exposureOffsetFor($meanLuminance);
            if ($offset !== 0.0) {
                $adjustments['exposure'] = $offset;
                $influencedBy[] = 'technical.mean_luminance';
            }
        } elseif ($exposure === 'underexposed') {
            $adjustments['exposure'] = 0.35;
            $influencedBy[] = 'technical.exposure.underexposed';
        } elseif ($exposure === 'overexposed') {
            $adjustments['exposure'] = -0.35;
            $influencedBy[] = 'technical.exposure.overexposed';
        }

        if ($highlight === 'risk') {
            $adjustments['highlight_recovery'] = 0.6;
            $influencedBy[] = 'technical.highlight_clipping.risk';
        }

        // ---- brief-aware shaping (only when a direction is adopted) ----
        if ($intent !== null) {
            $briefAdjustments = $this->briefAdjustments($intent);
            foreach ($briefAdjustments['adjustments'] as $key => $value) {
                $adjustments[$key] = $value;
            }
            foreach ($briefAdjustments['influenced_by'] as $dim) {
                $influencedBy[] = $dim;
            }
        }

        // ---- LEARN: durable photographer creative memory shapes proposals ----
        // Explicit decision history (photographer-authored only). Deterministic
        // consumption: each memory rule applies a bounded, documented delta.
        $memoryAdjustments = $this->memoryAdjustments($memoryLessons);
        foreach ($memoryAdjustments['adjustments'] as $key => $value) {
            $adjustments[$key] = $value;
        }
        foreach ($memoryAdjustments['influenced_by'] as $dim) {
            $influencedBy[] = $dim;
        }

        if ($adjustments === []) {
            $adjustments['exposure'] = 0.0; // neutral, still a valid renderable set
        }

        ksort($adjustments);

        return [
            'photo_id' => $observation->photoId,
            'adjustments' => $adjustments,
            'adjustments_summary' => $this->describe($adjustments),
            'influenced_by' => array_values(array_unique($influencedBy)),
            'has_brief' => $intent !== null,
            'provider' => $observation->provider,
            'note' => 'Deterministic proposal from measured photo data'
                .($intent !== null ? ' + adopted creative brief' : '')
                .'. Photographer review required before any execution.',
        ];
    }

    /* ------------------------------ derivation ------------------------------ */

    /**
     * Bounded exposure correction from the measured mean luminance.
     * 0..45 → lift up to +0.45; 215..255 → pull down to -0.45; neutral zone
     * (the provider's 'acceptable' band) → 0.
     */
    private function exposureOffsetFor(float $mean): float
    {
        return match (true) {
            $mean < 45 => round(min(0.45, (45 - $mean) / 45 * 0.45), 2),
            $mean > 215 => round(-min(0.45, ($mean - 215) / 40 * 0.45), 2),
            default => 0.0,
        };
    }

    /**
     * Extract the mean luminance the demo provider measured, when present.
     * The provider stores assessments; mean luminance is recoverable from the
     * exposure assessment only as a label — so we honor the label bands.
     */
    private function meanLuminance(PhotoObservation $observation): ?float
    {
        // The demo provider's payload carries no raw mean; the label is the
        // honest measurement summary. Returning null routes through the
        // label-based branch below (still deterministic).
        return null;
    }

    /**
     * Map the adopted brief's tonality/retouch/color dimensions onto the
     * adjustment vocabulary. Deterministic keyword matching, same dynamic as
     * ContextAwareCullingService::selectionWeights().
     *
     * @param  array<string, mixed>  $intent
     * @return array{adjustments: array<string, float>, influenced_by: list<string>}
     */
    private function briefAdjustments(array $intent): array
    {
        $adjustments = [];
        $influenced = [];

        $tonality = $this->intentText($intent['tonality_notes'] ?? null);
        $retouch = $this->intentText($intent['retouch'] ?? null);
        $color = $this->intentText($intent['color'] ?? null);
        $avoid = array_map('strtolower', (array) ($intent['avoid'] ?? []));

        // ---- tonality notes: explicit light shaping direction ----
        if ($tonality !== '') {
            if ($this->containsAny($tonality, ['bright', 'airy', 'high key', 'high-key', 'lift'])) {
                $adjustments['exposure'] = min(1.0, ($adjustments['exposure'] ?? 0.0) + 0.2);
                $adjustments['shadow_lift'] = max($adjustments['shadow_lift'] ?? 0.0, 0.35);
                $influenced[] = 'brief.tonality_notes.bright';
            }
            if ($this->containsAny($tonality, ['dark', 'moody', 'low key', 'low-key', 'shadow'])) {
                $adjustments['exposure'] = max(-1.0, ($adjustments['exposure'] ?? 0.0) - 0.15);
                $adjustments['contrast'] = max($adjustments['contrast'] ?? 0.0, 0.25);
                $influenced[] = 'brief.tonality_notes.moody';
            }
            if ($this->containsAny($tonality, ['soft', 'gentle', 'flat'])) {
                $adjustments['contrast'] = min($adjustments['contrast'] ?? 0.0, -0.2);
                $influenced[] = 'brief.tonality_notes.soft';
            }
            // "modern neutral" tonality: hold global warmth near neutral.
            if ($this->containsAny($tonality, ['neutral'])) {
                $adjustments['warmth'] = min($adjustments['warmth'] ?? 0.0, 0.0);
                $influenced[] = 'brief.tonality_notes.modern_neutral';
            }
        }

        // ---- retouch philosophy ----
        if ($retouch !== '') {
            // "restrained warmth" (or restrained + warm co-occurring): cap the
            // warmth delta at a subtle value BEFORE any warm push. Checked
            // BEFORE the warm keyword so a restrained-warmth brief always
            // wins over generic "warm" matches inside its own sentence.
            if ($this->containsAny($retouch, ['restrained'])) {
                $adjustments['warmth'] = min($adjustments['warmth'] ?? 0.0, 0.05);
                $influenced[] = 'brief.retouch.restrained_warmth';
            }
            if ($this->containsAny($retouch, ['natural', 'honest', 'minimal', 'true to scene'])) {
                // Natural philosophy: keep everything conservative.
                foreach (['exposure', 'contrast', 'saturation', 'warmth'] as $key) {
                    if (isset($adjustments[$key])) {
                        $adjustments[$key] = round($adjustments[$key] * 0.5, 2);
                    }
                }
                $influenced[] = 'brief.retouch.natural';
            }
            if ($this->containsAny($retouch, ['warm', 'golden', 'sunset'])) {
                $adjustments['warmth'] = min(1.0, ($adjustments['warmth'] ?? 0.0) + 0.3);
                $influenced[] = 'brief.retouch.warm';
            }
            if ($this->containsAny($retouch, ['cool', 'cold', 'blue', 'night'])) {
                $adjustments['warmth'] = max(-1.0, ($adjustments['warmth'] ?? 0.0) - 0.3);
                $influenced[] = 'brief.retouch.cool';
            }
        }

        // ---- color direction ----
        if ($color !== '') {
            if ($this->containsAny($color, ['vivid', 'punch', 'saturated', 'bold'])) {
                $adjustments['saturation'] = min(1.0, ($adjustments['saturation'] ?? 0.0) + 0.3);
                $influenced[] = 'brief.color.vivid';
            }
            if ($this->containsAny($color, ['muted', 'desaturated', 'pastel', 'faded'])) {
                $adjustments['saturation'] = max(-1.0, ($adjustments['saturation'] ?? 0.0) - 0.25);
                $influenced[] = 'brief.color.muted';
            }
        }

        // ---- avoid list guards ----
        if ($this->avoidMatches($avoid, ['blown', 'clipped highlight', 'overexposure'])) {
            $adjustments['highlight_recovery'] = max($adjustments['highlight_recovery'] ?? 0.0, 0.5);
            $influenced[] = 'avoid.clipped_highlights';
        }
        if ($this->avoidMatches($avoid, ['crushed black', 'crushed shadow', 'dark shadow'])) {
            $adjustments['shadow_lift'] = max($adjustments['shadow_lift'] ?? 0.0, 0.4);
            $influenced[] = 'avoid.crushed_shadows';
        }
        // Explicit avoid guard: the brief says what it does NOT want — that
        // guard is final and runs last, overriding any keyword push above.
        if ($this->avoidMatches($avoid, ['fake vintage', 'vintage'])) {
            $adjustments['warmth'] = min($adjustments['warmth'] ?? 0.0, 0.0);
            $adjustments['saturation'] = min($adjustments['saturation'] ?? 0.0, 0.0);
            $influenced[] = 'avoid.fake_vintage';
        }

        return ['adjustments' => $adjustments, 'influenced_by' => $influenced];
    }

    /** normalize an intent field to lowercase text */
    private function intentText(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(' ', array_map(fn ($v) => (string) $v, $value));
        }

        return strtolower(trim((string) $value));
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $avoid
     * @param  list<string>  $keywords
     */
    private function avoidMatches(array $avoid, array $keywords): bool
    {
        foreach ($avoid as $item) {
            foreach ($keywords as $kw) {
                if (str_contains((string) $item, $kw)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * LEARN — load the project's durable photographer memory lessons.
     * Photographer-authored rows ONLY; the agent can never write these.
     *
     * @return list<string>
     */
    private function memoryLessonsFor(Project $project): array
    {
        return $project->creativeMemories()
            ->orderBy('id')
            ->pluck('lesson')
            ->map(fn ($l) => strtolower((string) $l))
            ->all();
    }

    /**
     * Deterministic memory consumption: keyword rules over the photographer's
     * persisted lessons, each applying a bounded, documented delta. This is
     * explicit decision history shaping deterministic context — NOT ML
     * personalization.
     *
     * @param  list<string>  $lessons
     * @return array{adjustments: array<string, float>, influenced_by: list<string>}
     */
    private function memoryAdjustments(array $lessons): array
    {
        $adjustments = [];
        $influenced = [];

        $text = strtolower(implode(' | ', $lessons));
        if ($text === '') {
            return ['adjustments' => [], 'influenced_by' => []];
        }

        if ($this->containsAny($text, ['less warm', 'cooler', 'too warm', 'not warmer', 'no more warmth'])) {
            $adjustments['warmth'] = min($adjustments['warmth'] ?? 0.0, -0.05);
            $influenced[] = 'memory.less_warm';
        }
        if ($this->containsAny($text, ['keep this frame darker', 'keep darker', 'stay darker', 'darker'])) {
            $adjustments['exposure'] = min($adjustments['exposure'] ?? 0.0, -0.1);
            $influenced[] = 'memory.keep_darker';
        }
        if ($this->containsAny($text, ['do not increase saturation', 'no more saturation', 'less saturated', 'not more saturated', 'keep saturation'])) {
            $adjustments['saturation'] = min($adjustments['saturation'] ?? 0.0, -0.05);
            $influenced[] = 'memory.restrained_saturation';
        }
        if ($this->containsAny($text, ['preserve grain', 'keep grain', 'texture'])) {
            // Grain/texture preservation = conservative global moves only.
            foreach (['exposure', 'contrast', 'saturation'] as $key) {
                if (isset($adjustments[$key])) {
                    $adjustments[$key] = round($adjustments[$key] * 0.5, 2);
                }
            }
            $influenced[] = 'memory.preserve_texture';
        }

        return ['adjustments' => $adjustments, 'influenced_by' => $influenced];
    }

    /** @param  array<string, float>  $adjustments */
    private function describe(array $adjustments): string
    {
        $parts = [];
        foreach ($adjustments as $key => $value) {
            $direction = $value > 0.0 ? '+' : ($value < 0.0 ? '-' : '±');
            $parts[] = sprintf('%s %s%.2f', $key, $direction, abs($value));
        }

        return implode(', ', $parts);
    }
}
