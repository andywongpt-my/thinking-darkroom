<?php

namespace App\Services\Qa;

use App\Domain\Culling\PhotoObservation;
use App\Domain\Domain;
use App\Models\Photo;
use App\Models\PhotoDerivative;
use App\Models\Project;
use App\Models\QaFinding;
use App\Services\CreativeRoomService;
use App\Services\Culling\ContextAwareCullingService;

/**
 * Sprint 4 — Consistency QA (REVIEW stage of the product loop).
 *
 * Deterministic, observation-driven consistency review across the photos in
 * scope. Consumes:
 *   - the same persisted PhotoObservations as culling and retouch,
 *   - the applied adjustments recorded on approved_render derivatives,
 *   - and the ADOPTED Creative Brief (project intent).
 *
 * Findings carry structure: photo_id, category, severity, observation,
 * expected (project target), explanation, influenced_by, status.
 *
 * AUTHORITY SEMANTICS (documented, tested): this scan PERSISTS qa_findings,
 * so it is NOT read-only. Authority = ANALYZE (derives + persists non-final
 * analysis; never makes creative decisions). readOnlyHint = false.
 *
 * COUNTERFACTUAL CONTRACT: severity is judged RELATIVE TO THE ADOPTED BRIEF.
 * A warm outlier under a "restrained warmth" brief is flagged medium/high;
 * the same outlier under a "warm romantic editorial" brief is materially
 * reduced or absent. Only the brief changes; the pixels do not.
 *
 * Deterministic: identical observations + identical brief produce identical
 * findings. No face/identity QA, no VLM, no randomness.
 */
class ConsistencyQaService
{
    public function __construct(
        private readonly ContextAwareCullingService $culling,
        private readonly CreativeRoomService $creative,
    ) {}

    /**
     * Run the consistency review and persist findings.
     *
     * @param  list<string>  $focus  which consistency dimensions to check
     * @return array<string, mixed> review summary
     */
    public function review(Project $project, string $scope = 'selected', array $focus = []): array
    {
        $query = $project->photos()->orderBy('id');
        if ($scope === 'selected') {
            $query->where('selection_state', Domain::SELECTION_SELECTED);
        } elseif ($scope === 'culled') {
            $query->where('selection_state', Domain::SELECTION_CULLED);
        }
        $photos = $query->get();

        $focus = $focus !== [] ? $focus : [
            'exposure_consistency',
            'warmth_consistency',
            'saturation_consistency',
            'contrast_consistency',
            'creative_direction_drift',
            'retouch_coverage',
        ];

        $observations = $this->culling->observationsFor($project);
        $direction = $this->creative->structuredIntentFor($project);
        $intent = $direction['intent'] ?? null;
        $created = [];

        $checked = $photos->filter(fn ($p) => isset($observations[$p->id]));
        $unobserved = $photos->count() - $checked->count();

        if ($checked->count() > 1) {
            // ---- exposure consistency across the set ----
            if (in_array('exposure_consistency', $focus, true)) {
                $finding = $this->exposureConsistency($project, $checked, $observations);
                if ($finding !== null) {
                    $created[] = $finding;
                }
            }

            // ---- tonal-drift vs the ADOPTED BRIEF (warmth/saturation/
            // contrast) using derivative adjustments + observations. ----
            foreach (['warmth', 'saturation', 'contrast'] as $dimension) {
                if (in_array($dimension.'_consistency', $focus, true)) {
                    $finding = $this->adjustmentDrift($project, $dimension, $checked, $observations, $intent);
                    if ($finding !== null) {
                        $created[] = $finding;
                    }
                }
            }

            // ---- creative-direction drift vs the brief ----
            if (in_array('creative_direction_drift', $focus, true)) {
                $finding = $this->creativeDirectionDrift($project, $checked, $observations, $intent);
                if ($finding !== null) {
                    $created[] = $finding;
                }
            }

            // ---- retouch coverage: selected frames missing derivatives ----
            if (in_array('retouch_coverage', $focus, true)) {
                $finding = $this->retouchCoverage($project, $checked);
                if ($finding !== null) {
                    $created[] = $finding;
                }
            }
        }

        if ($unobserved > 0) {
            $created[] = QaFinding::create([
                'project_id' => $project->id,
                'severity' => 'info',
                'category' => 'analysis_coverage',
                'message' => "{$unobserved} photo(s) in scope have no observation yet — run analyze_project_photos for full coverage.",
                'details' => ['unobserved' => $unobserved, 'scope' => $scope],
            ]);
        }

        if ($created === []) {
            $created[] = QaFinding::create([
                'project_id' => $project->id,
                'severity' => 'info',
                'category' => 'consistency',
                'message' => 'Consistency review over '.$checked->count().' photo(s) in scope ['.$scope.']: no inconsistencies detected'
                    .($intent !== null ? ' against the adopted creative direction.' : '.'),
                'details' => ['scope' => $scope, 'focus' => $focus, 'checked_at' => now()->toISOString()],
            ]);
        }

        return [
            'project_id' => $project->id,
            'scope' => $scope,
            'focus' => $focus,
            'has_brief' => $intent !== null,
            'photos_checked' => $photos->count(),
            'observations_used' => $checked->count(),
            'created_findings' => collect($created)->map(fn ($f) => $f->only([
                'id', 'severity', 'category', 'message', 'photo_id', 'details',
            ]))->values(),
        ];
    }

    /**
     * Exposure drift across the set: frames whose measured luminance band
     * deviates strongly from the set's dominant band become findings.
     *
     * @param  iterable<Photo>  $photos
     * @param  array<int, PhotoObservation>  $observations
     */
    private function exposureConsistency(Project $project, $photos, array $observations): ?QaFinding
    {
        $bands = [];
        foreach ($photos as $photo) {
            $band = $observations[$photo->id]->technical['exposure']['assessment'] ?? 'unknown';
            if ($band !== 'unknown') {
                $bands[$photo->id] = $band;
            }
        }

        if (count($bands) < 2) {
            return null;
        }

        $counts = array_count_values($bands);
        arsort($counts);
        $dominant = array_key_first($counts);

        $deviants = collect($bands)
            ->filter(fn ($band) => $band !== $dominant && in_array($band, ['underexposed', 'overexposed'], true));

        if ($deviants->isEmpty()) {
            return null;
        }

        return QaFinding::create([
            'project_id' => $project->id,
            'severity' => 'warning',
            'category' => 'exposure_consistency',
            'message' => count($deviants).' of '.count($bands).' photo(s) deviate from the dominant exposure band ['.$dominant.'].',
            'details' => [
                'dominant_band' => $dominant,
                'deviant_photo_ids' => $deviants->keys()->values(),
                'bands' => $bands,
            ],
        ]);
    }

    /**
     * Tonal drift on ONE dimension (warmth | saturation | contrast).
     *
     * Source of truth for "applied look": the adjustments persisted on each
     * photo's approved_render derivative. A photo is an OUTLIER when its
     * applied value on the dimension is materially more extreme than the
     * selected set's median AND the drift runs OPPOSITE to (or beyond) the
     * adopted brief's stated direction. Outlier severity is judged relative
     * to the brief — this is the QA counterfactual gate.
     *
     * @param  array<int, PhotoObservation>  $observations
     */
    private function adjustmentDrift(Project $project, string $dimension, $photos, array $observations, ?array $intent): ?QaFinding
    {
        $selected = $photos->filter(fn ($p) => $p->selection_state === Domain::SELECTION_SELECTED);
        if ($selected->count() < 2) {
            return null;
        }

        $applied = $this->appliedAdjustments($project, $selected);
        if (count($applied) < 2) {
            return null; // need at least two rendered frames to form a "set look"
        }

        $values = collect($applied)->map(fn ($a) => (float) ($a[$dimension] ?? 0.0));
        $median = $this->median($values->values()->all());

        foreach ($values as $photoId => $value) {
            $drift = $value - $median;
            if (abs($drift) < 0.15) {
                continue; // within set tolerance
            }

            // Counterfactual core: does this drift violate the brief?
            $judgment = $this->judgeDriftAgainstBrief($dimension, $drift, $intent);

            if ($judgment['severity'] === null) {
                continue; // brief explicitly welcomes this drift
            }

            return QaFinding::create([
                'project_id' => $project->id,
                'photo_id' => $photoId,
                'severity' => $judgment['severity'],
                'category' => $dimension.'_consistency',
                'message' => 'Frame '.$photoId.' is '
                    .($drift > 0 ? 'stronger' : 'lower').' on '.$dimension
                    .' ('.round($value, 2).') than the surrounding selected set (median '.round($median, 2).').',
                'details' => [
                    'observation' => sprintf('%s = %+.2f, set median = %+.2f, drift = %+.2f', $dimension, $value, $median, $drift),
                    'expected' => $judgment['expected'],
                    'explanation' => $judgment['explanation'],
                    'influenced_by' => $judgment['influenced_by'],
                    'set_median' => round($median, 2),
                    'photo_value' => round($value, 2),
                ],
            ]);
        }

        return null;
    }

    /**
     * Creative-direction drift: an intentionally/intensively edited frame
     * whose applied tonal profile pulls against the adopted direction's
     * explicit tonality/retouch notes.
     *
     * @param  array<int, PhotoObservation>  $observations
     */
    private function creativeDirectionDrift(Project $project, $photos, array $observations, ?array $intent): ?QaFinding
    {
        if ($intent === null) {
            return null; // no adopted direction → nothing to drift from
        }

        $selected = $photos->filter(fn ($p) => $p->selection_state === Domain::SELECTION_SELECTED);
        if ($selected->count() < 2) {
            return null;
        }

        $applied = $this->appliedAdjustments($project, $selected);
        if (count($applied) < 2) {
            return null;
        }

        // Aggregate brief tonal stance.
        $briefText = strtolower(implode(' ', array_map('strval', array_filter([
            $intent['tonality_notes'] ?? '',
            $intent['retouch'] ?? '',
            is_array($intent['avoid'] ?? null) ? implode(' ', $intent['avoid']) : '',
        ]))));

        $wantsWarm = str_contains($briefText, 'warm') && ! str_contains($briefText, 'restrained warmth') && ! str_contains($briefText, 'restrained');
        $wantsNeutral = str_contains($briefText, 'neutral') || str_contains($briefText, 'restrained warmth');

        foreach ($applied as $photoId => $adjustments) {
            $warmth = (float) ($adjustments['warmth'] ?? 0.0);
            $saturation = (float) ($adjustments['saturation'] ?? 0.0);

            $driftsWarm = $warmth >= 0.15 || $saturation >= 0.2;

            if ($wantsNeutral && $driftsWarm) {
                return QaFinding::create([
                    'project_id' => $project->id,
                    'photo_id' => $photoId,
                    'severity' => 'medium',
                    'category' => 'creative_direction_drift',
                    'message' => 'This frame is warmer than the surrounding selected set and drifts from the adopted direction (restrained warmth / modern neutral).',
                    'details' => [
                        'observation' => sprintf('applied warmth %+.2f, saturation %+.2f', $warmth, $saturation),
                        'expected' => 'restrained warmth / modern neutral per the adopted Creative Brief',
                        'explanation' => 'The adopted creative direction calls for restrained warmth and a modern neutral look; this frame\u2019s applied adjustments push noticeably warmer than the rest of the selected set.',
                        'influenced_by' => ['brief.retouch.restrained_warmth', 'brief.tonality_notes.modern_neutral'],
                        'recommendation' => 'REVIEW',
                    ],
                ]);
            }

            // Warm-romantic briefs welcome warm frames: no finding (proves the
            // counterfactual — severity disappears when the brief changes).
        }

        return null;
    }

    /**
     * Applied adjustments per photo from approved_render derivatives.
     *
     * @return array<int, array<string, float>> photo_id => adjustments
     */
    private function appliedAdjustments(Project $project, $photos): array
    {
        return PhotoDerivative::where('project_id', $project->id)
            ->where('type', Domain::DERIVATIVE_APPROVED_RENDER)
            ->whereIn('photo_id', $photos->pluck('id'))
            ->get()
            ->filter(fn ($d) => is_array($d->adjustments) && $d->adjustments !== [])
            ->mapWithKeys(fn ($d) => [$d->photo_id => $d->adjustments])
            ->all();
    }

    /**
     * Judge one drift against the adopted brief. Deterministic.
     *
     * @return array{severity: string|null, expected: string, explanation: string, influenced_by: list<string>}
     */
    private function judgeDriftAgainstBrief(string $dimension, float $drift, ?array $intent): array
    {
        $expected = 'coherence with the selected set'.($intent !== null ? ' and the adopted creative direction' : '');
        $influenced = $intent !== null ? ['brief.adopted_direction'] : [];

        if ($intent === null) {
            // No brief: plain set-consistency only.
            return [
                'severity' => 'info',
                'expected' => $expected,
                'explanation' => 'Drift relative to the selected set (no adopted brief to judge against).',
                'influenced_by' => [],
            ];
        }

        $briefText = strtolower(implode(' ', array_map('strval', array_filter([
            $intent['tonality_notes'] ?? '',
            $intent['retouch'] ?? '',
            is_array($intent['color'] ?? null) ? implode(' ', $intent['color']) : (string) ($intent['color'] ?? ''),
            is_array($intent['avoid'] ?? null) ? implode(' ', $intent['avoid']) : '',
        ]))));

        if ($dimension === 'warmth') {
            $restrained = str_contains($briefText, 'restrained') || str_contains($briefText, 'neutral') || str_contains($briefText, 'natural');
            $welcomesWarm = str_contains($briefText, 'warm') && ! $restrained;

            if ($drift > 0) {
                if ($welcomesWarm) {
                    // Brief encourages warmth → warm outlier is intended.
                    return ['severity' => null, 'expected' => 'warm romantic per the adopted brief', 'explanation' => 'The adopted direction encourages warmth; this warm frame serves the intent.', 'influenced_by' => ['brief.retouch.warmth_encouraged']];
                }

                if ($restrained) {
                    return [
                        'severity' => 'medium',
                        'expected' => 'restrained warmth per the adopted brief',
                        'explanation' => 'The adopted direction calls for restrained warmth; this frame drifts warmer than both the set and that intent.',
                        'influenced_by' => ['brief.retouch.restrained_warmth'],
                    ];
                }
            }
        }

        if ($dimension === 'saturation' && str_contains($briefText, 'muted') && $drift > 0) {
            return [
                'severity' => 'low',
                'expected' => 'muted color per the adopted brief',
                'explanation' => 'The adopted direction favors muted color; this frame is more saturated than the set.',
                'influenced_by' => ['brief.color.muted'],
            ];
        }

        return [
            'severity' => 'info',
            'expected' => $expected,
            'explanation' => 'Measurable drift relative to the selected set; flagged for photographer review.',
            'influenced_by' => $influenced,
        ];
    }

    /** @param  list<float>  $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }

        return $n % 2 === 1
            ? $values[intdiv($n, 2)]
            : ($values[$n / 2 - 1] + $values[$n / 2]) / 2.0;
    }

    /**
     * Retouch coverage: selected photos still awaiting retouch while their
     * group siblings already have an approved render.
     */
    private function retouchCoverage(Project $project, $photos): ?QaFinding
    {
        $selected = $photos->filter(fn ($p) => $p->selection_state === Domain::SELECTION_SELECTED);
        if ($selected->isEmpty()) {
            return null;
        }

        $withRender = PhotoDerivative::where('project_id', $project->id)
            ->where('type', Domain::DERIVATIVE_APPROVED_RENDER)
            ->pluck('photo_id')
            ->all();

        $missing = $selected->filter(
            fn ($p) => ! in_array($p->id, $withRender) && $p->retouch_state !== Domain::RETOUCH_NONE,
        );

        if ($missing->isEmpty()) {
            return null;
        }

        return QaFinding::create([
            'project_id' => $project->id,
            'severity' => 'info',
            'category' => 'retouch_coverage',
            'message' => count($missing).' selected photo(s) are marked retouched but have no approved render derivative yet.',
            'details' => [
                'missing_photo_ids' => $missing->pluck('id')->values(),
            ],
        ]);
    }
}
