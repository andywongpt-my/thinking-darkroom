<?php

namespace App\Http\Controllers\Webmcp;

use App\Domain\Culling\PhotoObservation;
use App\Domain\Domain;
use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\Project;
use App\Services\Culling\ContextAwareCullingService;
use App\Services\ToolCallAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 3 — context-aware culling READ tools.
 *
 * These expose STRUCTURED OBSERVATIONS and RECOMMENDATIONS to the agent —
 * never final decisions. Every response labels its provenance:
 *   technical → pixel_analysis (deterministic GD statistics)
 *   creative  → demo_sidecar_annotation (human-authored demo labels)
 *
 * All are READ authority: analysis and recommendation only.
 */
class CullingController extends Controller
{
    public function __construct(
        private readonly ContextAwareCullingService $culling,
        private readonly ToolCallAuditService $audit,
    ) {}

    /** get_photo_analysis — one photo's observation (+ recommendation against current brief). */
    public function photoAnalysis(Request $request, Project $project, Photo $photo): JsonResponse
    {
        $start = hrtime(true);

        if ($photo->project_id !== $project->id) {
            $this->audit->record(
                $request, $project, $request->user(), 'get_photo_analysis', Domain::AUTHORITY_READ,
                ['project_id' => $project->id, 'photo_id' => $photo->id],
                ['error' => 'photo does not belong to project'], Domain::RESULT_DENIED,
            );

            return response()->json(['error' => 'Photo does not belong to project.'], 404);
        }

        $observation = $this->culling->observationFor($photo);

        if ($observation === null) {
            // Analyze lazily so an agent reading a fresh upload still gets data.
            $this->culling->analyzeProject($project);
            $observation = $this->culling->observationFor($photo);
        }

        if ($observation === null) {
            return response()->json(['error' => 'Photo could not be analyzed.'], 422);
        }

        $direction = app(\App\Services\CreativeRoomService::class)->structuredIntentFor($project);
        $recommendation = $this->culling->recommend($observation, $direction['intent'] ?? null);

        $this->audit->record(
            $request, $project, $request->user(), 'get_photo_analysis', Domain::AUTHORITY_READ,
            ['project_id' => $project->id, 'photo_id' => $photo->id],
            [
                'filename' => $photo->filename,
                'recommendation' => $recommendation['recommendation'],
                'duration_ms' => (hrtime(true) - $start) / 1e6,
            ],
        );

        return response()->json([
            'project_id' => $project->id,
            'photo' => PhotoController::photoSummary($photo),
            'observation' => $this->observationPayload($observation),
            'recommendation' => $recommendation,
        ]);
    }

    /** get_culling_context — project-wide analysis state + recommendations. */
    public function cullingContext(Request $request, Project $project): JsonResponse
    {
        $start = hrtime(true);

        // Ensure every photo has an observation before recommending.
        $this->culling->analyzeProject($project);

        $result = $this->culling->recommendForProject($project);
        $result['context'] = $this->culling->contextSummary($project);

        $this->audit->record(
            $request, $project, $request->user(), 'get_culling_context', Domain::AUTHORITY_READ,
            ['project_id' => $project->id],
            [
                'photos_observed' => $result['context']['photos_observed'],
                'has_direction' => $result['has_direction'],
                'duration_ms' => (hrtime(true) - $start) / 1e6,
            ],
        );

        return response()->json($result);
    }

    /**
     * analyze_project_photos — ANALYZE-authority analysis run. Idempotent:
     * already-observed photos keep their stable evidence. This only ever
     * creates photo_observations rows — never selection changes, never
     * proposals. (ANALYZE, not READ: the run persists non-final evidence,
     * so it is honestly advertised as mutating/non-read-only.)
     */
    public function analyzeProject(Request $request, Project $project): JsonResponse
    {
        $start = hrtime(true);

        $analyzed = $this->culling->analyzeProject($project);
        $observations = $this->culling->observationsFor($project);

        // ANALYZE authority: this run PERSISTS photo_observations (non-final
        // evidence). It mutates no photographer-facing state — never
        // proposals, never selection_state — but it is not read-only.
        $this->audit->record(
            $request, $project, $request->user(), 'analyze_project_photos', Domain::AUTHORITY_ANALYZE,
            ['project_id' => $project->id],
            [
                'newly_analyzed' => $analyzed,
                'total_observed' => count($observations),
                'duration_ms' => (hrtime(true) - $start) / 1e6,
            ],
        );

        return response()->json([
            'project_id' => $project->id,
            'provider' => $this->culling->contextSummary($project)['provider'],
            'newly_analyzed' => $analyzed,
            'total_observed' => count($observations),
            'observations' => collect($observations)
                ->map(fn (PhotoObservation $o) => $this->observationPayload($o))
                ->values(),
        ]);
    }

    /**
     * JSON shape with explicit per-section provenance. The technical section
     * came from pixel analysis; the creative section from the sidecar
     * annotation (or honestly unobserved). The API never blurs this line.
     *
     * @return array<string, mixed>
     */
    private function observationPayload(PhotoObservation $o): array
    {
        $payload = $o->toPayload();

        $payload['technical_provenance'] = 'pixel_analysis';
        $payload['creative_provenance'] = 'demo_sidecar_annotation';

        return $payload;
    }
}
