<?php

namespace App\Http\Controllers\Webmcp;

use App\Domain\Domain;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Qa\ConsistencyQaService;
use App\Services\ToolCallAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QaController extends Controller
{
    public function __construct(
        private readonly ToolCallAuditService $audit,
        private readonly ConsistencyQaService $consistency,
    ) {}

    /**
     * run_consistency_review — performs a deterministic, observation-driven
     * consistency review over the project's photos and creates qa_findings.
     *
     * Sprint 4: consumes the same persisted PhotoObservations as culling and
     * retouch (single source of analysis truth) and checks the SET of frames
     * for consistency: exposure drift, white-balance proxies inside
     * similarity groups, and retouch derivative coverage.
     *
     * Creates findings (ANALYZE — persists non-final analysis) and never
     * modifies creative state.
     */
    public function review(Request $request, Project $project): JsonResponse
    {
        $this->authorize('analyze', $project);

        $validated = $request->validate([
            'scope' => ['sometimes', 'in:selected,all,culled'],
            'focus' => ['sometimes', 'array'],
            'focus.*' => ['string', 'max:48'],
        ]);

        $scope = $validated['scope'] ?? 'selected';
        $focus = $validated['focus'] ?? [];

        $summary = $this->consistency->review($project, $scope, $validated['focus'] ?? []);

        $this->audit->record(
            $request,
            $project,
            $request->user(),
            'run_consistency_review',
            Domain::AUTHORITY_ANALYZE,
            $validated,
            [
                'created_findings' => count($summary['created_findings']),
                'photos_checked' => $summary['photos_checked'],
                'observations_used' => $summary['observations_used'],
            ],
        );

        return response()->json($summary, 201);
    }
}
