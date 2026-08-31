<?php

namespace App\Services\Culling;

use App\Domain\Culling\PhotoObservation;
use App\Domain\Domain;
use App\Models\Photo;
use App\Models\PhotoObservationRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\CreativeRoomService;
use App\Services\Media\MediaStore;
use App\Services\ToolCallAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/**
 * Sprint 3 — context-aware culling decision layer.
 *
 * Central question this service answers:
 *   "Given what this photographer said they are trying to create,
 *    which frames best serve that intent?"
 *
 * It consumes BOTH:
 *   A. structured photo observations (PhotoObservation — observed data), and
 *   B. the photographer-approved Creative Brief via
 *      CreativeRoomService::structuredIntentFor($project).
 *
 * and produces RECOMMENDATIONS (strong_keep | keep | review | reject_candidate)
 * with confidence, technical + creative rationale, a tradeoff explanation and
 * a traceable influenced_by list naming the brief dimensions that moved the
 * decision.
 *
 * HARD BOUNDARY: recommendations are never converted into final selection
 * states here. Only ProposalApplicator (via an approved proposal) or a
 * photographer's explicit decision changes selection_state. No hard-coded
 * demo photo ids, no hard-coded demo sentences.
 */
class ContextAwareCullingService
{
    public function __construct(
        private readonly CreativeRoomService $creative,
        private readonly PhotoAnalysisProvider $provider,
    ) {}

    /* ------------------------------- analysis ------------------------------- */

    /**
     * Analyze every unobserved photo in the project and persist observations.
     * Existing evidence stays stable except the explicit signature of a prior
     * unavailable asset: all technical fields are unknown at zero confidence
     * and every creative field is unobserved despite demo pixel provenance.
     */
    public function analyzeProject(Project $project): CullingAnalysisRun
    {
        $created = 0;
        $refreshed = 0;

        $photos = $project->photos()->orderBy('id')->get();

        foreach ($photos as $photo) {
            $existing = PhotoObservationRecord::where('photo_id', $photo->id)->first();
            if ($existing && ! $this->isUnavailableAssetObservation($existing)) {
                continue;
            }

            $observation = $this->provider->observe($photo);
            $attributes = [
                'payload' => $observation->toArray(),
                'provider' => $observation->provider,
                'provenance' => $observation->provenance,
                'similarity_group' => $observation->similarityGroup,
            ];

            if ($existing) {
                // A retry is safe: the signature means the prior row contains
                // no useful observation, never a photographer decision.
                $existing->forceFill($attributes)->save();
                $refreshed++;

                continue;
            }

            try {
                PhotoObservationRecord::create([
                    'photo_id' => $photo->id,
                    'project_id' => $project->id,
                    ...$attributes,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Concurrent analyzer raced us on the photo_id unique index —
                // the other writer's row is identical evidence; keep it.
                continue;
            }

            $created++;
        }

        return new CullingAnalysisRun($created, $refreshed);
    }

    /**
     * Detect the exact honest-but-degraded signature produced when a demo
     * asset was unavailable to both the pixel reader and sidecar loader.
     * Missing GD has its own provenance and is deliberately never retried.
     */
    private function isUnavailableAssetObservation(PhotoObservationRecord $record): bool
    {
        if ($record->provider !== Domain::OBSERVATION_PROVIDER_DEMO
            || $record->provenance !== Domain::OBSERVATION_PROVENANCES[Domain::OBSERVATION_PROVIDER_DEMO]) {
            return false;
        }

        $payload = $record->payload ?? [];
        $technical = is_array($payload['technical'] ?? null) ? $payload['technical'] : [];
        foreach (['sharpness', 'exposure', 'motion_blur', 'highlight_clipping'] as $dimension) {
            $assessment = $technical[$dimension] ?? null;
            if (! is_array($assessment)
                || ($assessment['assessment'] ?? null) !== 'unknown'
                || (float) ($assessment['confidence'] ?? -1) !== 0.0) {
                return false;
            }
        }

        $creative = is_array($payload['creative'] ?? null) ? $payload['creative'] : [];
        foreach (['expression', 'candidness', 'environmental_storytelling', 'compositional_fit', 'emotion_strength'] as $field) {
            if (($creative[$field] ?? null) !== 'unobserved') {
                return false;
            }
        }

        return (array) ($creative['mood'] ?? []) === [];
    }

    /**
     * Persisted observation for one photo, as the application DTO.
     */
    public function observationFor(Photo $photo): ?PhotoObservation
    {
        $record = PhotoObservationRecord::where('photo_id', $photo->id)->first();
        if (! $record) {
            return null;
        }

        return PhotoObservation::fromArray(
            $record->photo_id,
            $record->payload ?? [],
            $record->provider,
            $record->provenance,
            $record->similarity_group,
        );
    }

    /**
     * All persisted observations for the project keyed by photo id.
     *
     * @return array<int, PhotoObservation>
     */
    public function observationsFor(Project $project): array
    {
        return PhotoObservationRecord::where('project_id', $project->id)
            ->orderBy('photo_id')
            ->get()
            ->map(fn (PhotoObservationRecord $r) => PhotoObservation::fromArray(
                $r->photo_id,
                $r->payload ?? [],
                $r->provider,
                $r->provenance,
                $r->similarity_group,
            ))
            ->keyBy(fn (PhotoObservation $o) => $o->photoId)
            ->all();
    }

    /* ----------------------------- recommendations ----------------------------- */

    /**
     * Recommend for ONE photo. Pure function of (observation, intent).
     *
     * @param  array<string, mixed>|null  $intent  structuredIntentFor()['intent'] — null = no adopted direction
     * @return array<string, mixed> recommendation payload
     */
    public function recommend(PhotoObservation $observation, ?array $intent): array
    {
        if ($intent === null) {
            return $this->noBriefRecommendation($observation);
        }

        $scores = $this->scoreAgainstIntent($observation, $intent);

        $recommendation = $this->decide($observation, $scores);

        return [
            'photo_id' => $observation->photoId,
            'recommendation' => $recommendation,
            'confidence' => $this->confidence($observation, $scores, $recommendation),
            'technical_rationale' => $this->technicalRationale($observation),
            'creative_rationale' => $this->creativeRationale($observation, $scores),
            'tradeoff' => $this->tradeoffExplanation($observation, $scores, $recommendation),
            'influenced_by' => $scores['influenced_by'],
        ];
    }

    /**
     * Recommendations for the whole project (photos with observations).
     *
     * @return array<string, mixed>
     */
    public function recommendForProject(Project $project): array
    {
        $direction = $this->creative->structuredIntentFor($project);
        $intent = $direction['intent'] ?? null;

        $observations = $this->observationsFor($project);
        $photos = $project->photos()->orderBy('id')->get()->filter(
            fn (Photo $p) => isset($observations[$p->id]),
        );

        $recommendations = [];
        foreach ($photos as $photo) {
            /** @var PhotoObservation $observation */
            $observation = $observations[$photo->id];
            $recommendation = $this->recommend($observation, $intent);
            $recommendation['photo'] = [
                'id' => $photo->id,
                'filename' => $photo->filename,
                'url' => MediaStore::publicUrl($photo->path),
                'selection_state' => $photo->selection_state,
                'original_name' => $photo->original_name,
            ];
            $recommendation['similarity_group'] = $observation->similarityGroup;
            $recommendation['similarity_group_size'] = $observation->similarityGroup !== null
                ? count(array_filter($observations, fn (PhotoObservation $o) => $o->similarityGroup === $observation->similarityGroup))
                : 0;
            $recommendations[] = $recommendation;
        }

        return [
            'project_id' => $project->id,
            'has_direction' => $intent !== null,
            'provider' => $this->provider->name(),
            'provenance' => $this->provider->provenance(),
            'recommendations' => $recommendations,
        ];
    }

    /* -------------------------------- decision core -------------------------------- */

    /**
     * Score one observation against the structured intent.
     *
     * @param  array<string, mixed>  $intent
     * @return array{technical: float, creative: float, emotion_weight: float, technical_weight: float, emotion_strength: float, candid: float, posed: float, mood_match: float, influenced_by: list<string>}
     */
    private function scoreAgainstIntent(PhotoObservation $o, array $intent): array
    {
        $influenced = [];

        // ---- selection priority: what does the brief say wins ties? ----
        // Brief shape (from adopted concept content): selection_priorities is
        // either an assoc map {emotion: primary, technical: secondary} or a
        // structured object {primary, secondary}.
        [$emotionWeight, $technicalWeight] = $this->selectionWeights($intent, $influenced);

        // ---- avoid list: what did the photographer ask to avoid? ----
        $avoid = $this->avoidList($intent);
        $avoidPosed = $this->avoidMatches($avoid, ['posed', 'posing', 'pose', 'studio']);
        $avoidBlur = $this->avoidMatches($avoid, ['blur', 'motion']);
        $avoidSoft = $this->avoidMatches($avoid, ['soft', 'sharp', 'focus']);
        foreach (['avoid.overly_posed' => $avoidPosed, 'avoid.motion_blur' => $avoidBlur, 'avoid.soft_frames' => $avoidSoft] as $dim => $hit) {
            if ($hit) {
                $influenced[] = $dim;
            }
        }

        // ---- mood alignment ----
        $briefMoods = array_map('strtolower', (array) ($intent['mood'] ?? []));
        $photoMoods = array_map('strtolower', $o->mood());
        $moodMatch = $this->overlapScore($briefMoods, $photoMoods);
        if ($briefMoods !== [] && $photoMoods !== []) {
            $influenced[] = 'mood.alignment';
        }

        // ---- creative strengths ----
        $emotionStrength = $this->emotionScore($o);
        $candid = $this->candidnessScore($o);
        $storytelling = $this->storytellingScore($o);

        if ($emotionStrength >= 0.7) {
            $influenced[] = 'creative.emotional_strength';
        }
        if ($candid >= 0.7) {
            $influenced[] = 'creative.candidness';
        }
        if ($storytelling >= 0.7) {
            $influenced[] = 'creative.environmental_storytelling';
        }
        if ($moodMatch > 0) {
            $influenced[] = 'mood.alignment';
        }

        // Creative score blends the four creative dimensions. The blend
        // follows the brief: a precision-first brief (technical_weight >
        // emotion_weight) cares less about candidness and more about mood
        // alignment, so a posed-but-on-brief frame is not automatically a
        // creative miss. Emotion-first briefs keep the candidness weight.
        $precisionFirst = $technicalWeight > $emotionWeight;
        $candidWeight = $precisionFirst ? 0.10 : 0.25;
        $moodWeight = $precisionFirst ? 0.30 : 0.15;
        if ($precisionFirst) {
            $influenced[] = 'selection_priority.technical';
        }

        $creativeScore = round(
            (0.45 * $emotionWeight * $emotionStrength)
            + ($candidWeight * $candid)
            + (0.15 * $storytelling)
            + ($moodWeight * $moodMatch),
            4,
        );

        // ---- technical penalties from the brief's avoid list ----
        $technical = $this->technicalScore($o);
        $blurPenalty = $this->blurPenalty($o) * (0.6 + 0.4 * $technicalWeight) + ($avoidBlur ? 0.15 : 0.0);
        $softPenalty = $this->softnessPenalty($o) * (0.6 + 0.4 * $technicalWeight) + ($avoidSoft ? 0.15 : 0.0);

        return [
            'technical' => round(max(0.0, $technical - $blurPenalty - $softPenalty), 4),
            'creative' => $creativeScore,
            'emotion_weight' => $emotionWeight,
            'technical_weight' => $technicalWeight,
            'emotion_strength' => $emotionStrength,
            'candid' => $candid,
            'posed' => round(1.0 - $candid, 4),
            'mood_match' => $moodMatch,
            'influenced_by' => array_values(array_unique($influenced)),
        ];
    }

    /**
     * The actual decision. Real tradeoffs, driven by brief weights:
     *  - emotion-first brief: emotionally strong imperfect frames stay KEEP;
     *  - technical-first brief: the same frame drops (or flips);
     *  - heavily posed + avoid-posed: REVIEW even when sharp;
     *  - creatively weak: never better than REVIEW; weak + technical issues
     *    → reject_candidate.
     *
     * @param  array{technical: float, creative: float, emotion_weight: float, technical_weight: float, emotion_strength: float, candid: float, posed: float, mood_match: float, influenced_by: list<string>}  $s
     */
    private function decide(PhotoObservation $o, array $s): string
    {
        $avoidList = $this->lastAvoidList ?? [];
        $avoidBlur = $this->avoidMatches($avoidList, ['blur', 'motion']);
        $avoidSoft = $this->avoidMatches($avoidList, ['soft', 'sharp', 'focus']);

        $technicallyWeak = $s['technical'] < 0.45;
        $technicallyStrong = $s['technical'] >= 0.7;
        $creativelyStrong = $s['creative'] >= 0.62;
        $creativelyWeak = $s['creative'] < 0.34;
        $emotionDominant = $s['emotion_weight'] > $s['technical_weight'];

        // Deliberate aversion (posed / blur / soft) pulls toward review/reject.
        $avoidPosedHit = $s['posed'] >= 0.65
            && $this->avoidMatches($avoidList, ['posed', 'posing', 'pose', 'studio']);

        // Tradeoff case: strong emotion, technical imperfection.
        if ($creativelyStrong && $technicallyWeak) {
            // Emotion-first brief → keep (slight blur does not sink it).
            if ($emotionDominant) {
                return Domain::CULL_RECOMMEND_KEEP;
            }

            // Technical-first brief → the imperfection matters.
            return $avoidBlur || $avoidSoft
                ? Domain::CULL_RECOMMEND_REJECT_CANDIDATE
                : Domain::CULL_RECOMMEND_REVIEW;
        }

        // Strong on both axes.
        if ($creativelyStrong && $technicallyStrong) {
            return Domain::CULL_RECOMMEND_STRONG_KEEP;
        }

        // Creative miss on an otherwise good frame — the brief decides.
        if ($creativelyWeak) {
            return $avoidPosedHit || $s['mood_match'] === 0.0
                ? ($technicallyWeak ? Domain::CULL_RECOMMEND_REJECT_CANDIDATE : Domain::CULL_RECOMMEND_REVIEW)
                : Domain::CULL_RECOMMEND_REVIEW;
        }

        if ($avoidPosedHit) {
            return Domain::CULL_RECOMMEND_REVIEW;
        }

        // Everything else: keep when it clears the technical bar, else review.
        return $technicallyWeak ? Domain::CULL_RECOMMEND_REVIEW : Domain::CULL_RECOMMEND_KEEP;
    }

    /** @param  array{technical: float, creative: float, emotion_strength: float, influenced_by: list<string>}  $s */
    private function confidence(PhotoObservation $o, array $s, string $recommendation): float
    {
        // Recommendation confidence blends the observation confidences with
        // the decisiveness of the scoring gap. Never presented as certainty.
        $obsConfidence = 0.0;
        $count = 0;
        foreach ([$o->sharpness(), $o->exposure(), $o->motionBlur()] as $assessment) {
            if (is_array($assessment) && ($assessment['confidence'] ?? 0) > 0) {
                $obsConfidence += (float) $assessment['confidence'];
                $count++;
            }
        }
        $obsConfidence = $count > 0 ? $obsConfidence / $count : 0.5;

        $gap = abs($s['creative'] - $s['technical']);
        $decisiveness = min(1.0, 0.5 + $gap);

        // Extreme recommendations (strong_keep / reject_candidate) claim less
        // confidence than keep/review — they lean harder on judgment.
        $cap = in_array($recommendation, [Domain::CULL_RECOMMEND_STRONG_KEEP, Domain::CULL_RECOMMEND_REJECT_CANDIDATE], true)
            ? 0.88
            : 0.94;

        return round(min($cap, 0.55 * $obsConfidence + 0.45 * $decisiveness + 0.05), 2);
    }

    /* --------------------------------- rationales --------------------------------- */

    private function technicalRationale(PhotoObservation $o): string
    {
        $parts = [];
        $sharp = $o->sharpness();
        if (is_array($sharp) && $sharp['assessment'] !== 'unknown') {
            $parts[] = match ($sharp['assessment']) {
                'sharp' => 'focus is sharp',
                'slightly_soft' => 'slightly soft',
                'soft' => 'soft focus',
                default => (string) $sharp['assessment'],
            };
        }
        $blur = $o->motionBlur();
        if (is_array($blur) && in_array($blur['assessment'], ['mild', 'strong'], true)) {
            $parts[] = ($blur['assessment'] === 'strong' ? 'strong' : 'slight').' motion blur';
        }
        $clip = $o->highlightClipping();
        if (is_array($clip) && $clip['assessment'] === 'risk') {
            $parts[] = 'highlights near clipping';
        }
        $exp = $o->exposure();
        if (is_array($exp) && in_array($exp['assessment'], ['underexposed', 'overexposed'], true)) {
            $parts[] = (string) $exp['assessment'];
        }

        return $parts === [] ? 'Technically this frame is sound.' : 'Technically, this frame is '.implode(' with ', $parts).'.';
    }

    /**
     * @param  array{creative: float, emotion_strength: float, candid: float, mood_match: float}  $s
     */
    private function creativeRationale(PhotoObservation $o, array $s): string
    {
        $parts = [];
        if ($s['emotion_strength'] >= 0.7) {
            $parts[] = 'emotionally strong expression';
        } elseif ($s['emotion_strength'] <= 0.3) {
            $parts[] = 'weak expression';
        }
        if ($s['candid'] >= 0.7) {
            $parts[] = 'genuinely candid';
        } elseif ($s['candid'] <= 0.3) {
            $parts[] = 'heavily posed';
        }
        if ($s['mood_match'] > 0) {
            $parts[] = 'mood matches the adopted direction ('.implode(', ', $o->mood()).')';
        }

        return $parts === []
            ? 'Creatively this frame is unremarkable against the adopted direction.'
            : 'Creatively, this frame is '.implode(', ', $parts).'.';
    }

    /**
     * @param  array{technical: float, creative: float, emotion_weight: float, technical_weight: float, influenced_by: list<string>}  $s
     */
    private function tradeoffExplanation(PhotoObservation $o, array $s, string $recommendation): string
    {
        $technicalNote = $o->technicalSummary();
        $emotionNote = $s['creative'] >= 0.62 ? 'strong creative fit' : ($s['creative'] < 0.34 ? 'weak creative fit' : 'moderate creative fit');

        if ($recommendation === Domain::CULL_RECOMMEND_KEEP && $s['technical'] < 0.45 && $s['emotion_weight'] > $s['technical_weight']) {
            return "This frame is {$technicalNote}, but its {$emotionNote} carries it: the adopted Creative Brief prioritizes "
                .'emotional authenticity over technical perfection, so it remains a keep.';
        }

        if ($recommendation === Domain::CULL_RECOMMEND_REJECT_CANDIDATE && $s['technical'] >= 0.7) {
            return "Technically clean ({$technicalNote}), but the {$emotionNote} works against the adopted direction — "
                .'worth considering for culling, and your call to make.';
        }

        if ($recommendation === Domain::CULL_RECOMMEND_REVIEW) {
            return "Mixed signals: {$technicalNote} with {$emotionNote}. The adopted brief does not settle this one clearly — "
                .'review it yourself.';
        }

        if ($recommendation === Domain::CULL_RECOMMEND_STRONG_KEEP) {
            return "Both axes align: {$technicalNote}, and {$emotionNote} that matches the adopted direction.";
        }

        return "Keep — {$technicalNote}, {$emotionNote}, and nothing in the adopted brief argues against it.";
    }

    /* --------------------------------- no-brief fallback --------------------------------- */

    /**
     * No adopted Creative Brief: fall back safely to a neutral, technical-only
     * recommendation with LOW confidence and an honest explanation. This is
     * NOT generic AI scoring — it simply refuses to make creative claims
     * without the photographer's stated intent.
     *
     * @return array<string, mixed>
     */
    private function noBriefRecommendation(PhotoObservation $o): array
    {
        $raw = $this->technicalScore($o);
        $blur = $this->blurPenalty($o);
        $soft = $this->softnessPenalty($o);
        $score = max(0.0, $raw - $blur - $soft);

        $recommendation = $score >= 0.75
            ? Domain::CULL_RECOMMEND_REVIEW // even a clean frame is only a "review" without intent
            : Domain::CULL_RECOMMEND_REVIEW;

        return [
            'photo_id' => $o->photoId,
            'recommendation' => $recommendation,
            'confidence' => 0.3,
            'technical_rationale' => $this->technicalRationale($o),
            'creative_rationale' => 'No creative assessment: the project has no adopted Creative Brief, so creative fit cannot be evaluated.',
            'tradeoff' => 'No adopted Creative Brief found — I only flag technical observations and leave every creative judgment to you. '
                .'Adopt a creative direction to unlock context-aware recommendations.',
            'influenced_by' => [],
        ];
    }

    /* --------------------------------- scoring helpers --------------------------------- */

    /**
     * @param  array<string, mixed>  $intent
     * @param  list<string>  $influenced
     * @return array{0: float, 1: float}
     */
    private function selectionWeights(array $intent, array &$influenced): array
    {
        // Normalize both possible brief shapes into (emotionWeight, technicalWeight).
        $priority = $intent['selection_priority'] ?? ($intent['selection_priorities'] ?? null);

        $primary = null;
        $secondary = null;

        if (is_array($priority)) {
            if (isset($priority['primary']) || isset($priority['secondary'])) {
                $primary = strtolower((string) ($priority['primary'] ?? ''));
                $secondary = strtolower((string) ($priority['secondary'] ?? ''));
            } else {
                // assoc map: ['emotion' => 'primary', 'technical' => 'secondary']
                foreach ($priority as $dim => $rank) {
                    $rank = strtolower((string) $rank);
                    if ($rank === 'primary') {
                        $primary = strtolower((string) $dim);
                    } elseif ($rank === 'secondary') {
                        $secondary = strtolower((string) $dim);
                    }
                }
            }
        } elseif (is_string($priority)) {
            $primary = strtolower($priority);
        }

        // Default neutral: emotion and technical equally weighted.
        $emotionWeight = 0.5;
        $technicalWeight = 0.5;

        if ($primary !== null && $primary !== '') {
            if (str_contains($primary, 'emotion') || str_contains($primary, 'authentic')) {
                $emotionWeight = 0.78;
                $technicalWeight = 0.22;
                $influenced[] = 'selection_priority.emotion';
            } elseif (str_contains($primary, 'technic') || str_contains($primary, 'sharp') || str_contains($primary, 'precision')) {
                $emotionWeight = 0.22;
                $technicalWeight = 0.78;
                $influenced[] = 'selection_priority.technical';
            }
        }

        return [$emotionWeight, $technicalWeight];
    }

    /** @param  array<string, mixed>  $intent @return list<string> */
    private function avoidList(array $intent): array
    {
        $this->lastAvoidList = array_map('strtolower', (array) ($intent['avoid'] ?? []));

        return $this->lastAvoidList;
    }

    /** @param  list<string>  $avoid */
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

    private function overlapScore(array $briefMoods, array $photoMoods): float
    {
        if ($briefMoods === [] || $photoMoods === []) {
            return 0.0;
        }

        $hits = 0;
        foreach ($photoMoods as $pm) {
            foreach ($briefMoods as $bm) {
                if (str_contains($pm, $bm) || str_contains($bm, $pm)) {
                    $hits++;
                    break;
                }
            }
        }

        return round(min(1.0, $hits / max(1, count($photoMoods))), 4);
    }

    private function emotionScore(PhotoObservation $o): float
    {
        return $this->gradedScore($o->emotionStrength(), [
            'exceptional' => 1.0, 'strong' => 0.85, 'genuine' => 0.75, 'moderate' => 0.5,
            'subtle' => 0.45, 'weak' => 0.2, 'flat' => 0.1, 'none' => 0.0, 'unobserved' => 0.4,
        ]);
    }

    private function candidnessScore(PhotoObservation $o): float
    {
        return $this->gradedScore($o->candidness(), [
            'high' => 1.0, 'candid' => 1.0, 'mostly_candid' => 0.7, 'mixed' => 0.5,
            'semi_posed' => 0.35, 'low' => 0.15, 'posed' => 0.05, 'heavily_posed' => 0.0, 'unobserved' => 0.4,
        ]);
    }

    private function storytellingScore(PhotoObservation $o): float
    {
        return $this->gradedScore($o->environmentalStorytelling(), [
            'strong' => 0.95, 'rich' => 0.95, 'present' => 0.7, 'moderate' => 0.5,
            'weak' => 0.25, 'minimal' => 0.2, 'none' => 0.0, 'unobserved' => 0.4,
        ]);
    }

    /** @param array<string, float> $map */
    private function gradedScore(string $value, array $map): float
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return 0.4;
        }
        if (isset($map[$v])) {
            return $map[$v];
        }
        foreach ($map as $key => $score) {
            if ($key !== 'unobserved' && (str_contains($v, $key) || str_contains($key, $v))) {
                return $score;
            }
        }

        return 0.4;
    }

    /** Pure observation quality (before brief penalties). */
    private function technicalScore(PhotoObservation $o): float
    {
        $score = 0.8;
        $sharp = $o->sharpness();
        if (is_array($sharp)) {
            $score = match ($sharp['assessment']) {
                'sharp' => 0.9,
                'slightly_soft' => 0.55,
                'soft' => 0.25,
                'unknown' => 0.5,
                default => 0.6,
            };
        }
        $blur = $o->motionBlur();
        if (is_array($blur)) {
            $score -= match ($blur['assessment']) {
                'strong' => 0.35,
                'mild' => 0.12,
                default => 0.0,
            };
        }
        $clip = $o->highlightClipping();
        if (is_array($clip) && $clip['assessment'] === 'risk') {
            $score -= 0.1;
        }
        $exp = $o->exposure();
        if (is_array($exp) && in_array($exp['assessment'], ['underexposed', 'overexposed'], true)) {
            $score -= 0.12;
        }

        return round(max(0.0, min(1.0, $score)), 4);
    }

    private function blurPenalty(PhotoObservation $o): float
    {
        $blur = $o->motionBlur();

        return is_array($blur) ? match ($blur['assessment']) {
            'strong' => 0.3,
            'mild' => 0.08,
            default => 0.0,
        } : 0.0;
    }

    private function softnessPenalty(PhotoObservation $o): float
    {
        $sharp = $o->sharpness();

        return is_array($sharp) ? match ($sharp['assessment']) {
            'soft' => 0.3,
            'slightly_soft' => 0.1,
            default => 0.0,
        } : 0.0;
    }

    /** @var list<string> */
    private array $lastAvoidList = [];

    /* --------------------------- audit + propose support --------------------------- */

    /**
     * Build the audit payload for a recommend run (what the Agent Activity
     * panel shows about this analysis).
     *
     * @return array<string, mixed>
     */
    public function contextSummary(Project $project): array
    {
        $direction = $this->creative->structuredIntentFor($project);
        $observations = $this->observationsFor($project);
        $groups = [];
        foreach ($observations as $o) {
            if ($o->similarityGroup !== null) {
                $groups[$o->similarityGroup][] = $o->photoId;
            }
        }
        $groups = array_filter($groups, fn (array $g) => count($g) > 1);

        return [
            'has_direction' => $direction !== null,
            'adopted_concept' => $direction['adopted_concept']['title'] ?? null,
            'selection_priority' => $direction['intent']['selection_priority'] ?? null,
            'photos_observed' => count($observations),
            'duplicate_groups' => array_map(
                fn (array $ids) => ['photo_ids' => $ids, 'count' => count($ids)],
                array_values($groups),
            ),
            'provider' => $this->provider->name(),
        ];
    }

    /**
     * Record an analyzed-* analysis run in the agent tool-call audit trail.
     */
    public function audit(Request $request, Project $project, User $actor, string $tool, array $input, array $output, string $status = Domain::RESULT_COMPLETED): void
    {
        app(ToolCallAuditService::class)->record(
            $request, $project, $actor, $tool, Domain::AUTHORITY_READ, $input, $output, $status,
        );
    }
}

/**
 * Outcome of one explicit ANALYZE run. Created observations and corrected
 * prior asset-unavailable observations stay distinct for an honest UI/API.
 */
final readonly class CullingAnalysisRun
{
    public function __construct(
        public int $created,
        public int $refreshed,
    ) {}
}
