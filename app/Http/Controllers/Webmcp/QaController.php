<?php

namespace App\Http\Controllers\Webmcp;

use App\Domain\Domain;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\QaFinding;
use App\Services\ToolCallAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QaController extends Controller
{
    public function __construct(private readonly ToolCallAuditService $audit) {}

    /**
     * run_consistency_review — performs a deterministic consistency review
     * over the project's photos and creates qa_findings.
     *
     * This is a heuristic stand-in; a pixel-level AI consistency engine is a
     * Sprint 2 concern. It creates findings (PROPOSE) and never modifies
     * creative state.
     */
    public function review(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['sometimes', 'in:selected,all,culled'],
            'focus' => ['sometimes', 'array'],
        ]);

        $scope = $validated['scope'] ?? 'selected';
        $focus = $validated['focus'] ?? ['exposure_consistency', 'white_balance_consistency', 'metadata_completeness'];

        $query = $project->photos();
        if ($scope === 'selected') {
            $query->where('selection_state', Domain::SELECTION_SELECTED);
        } elseif ($scope === 'culled') {
            $query->where('selection_state', Domain::SELECTION_CULLED);
        }

        $photos = $query->get();
        $created = [];

        // Deterministic findings so the demo is reproducible.
        if ($photos->count() > 1) {
            $created[] = QaFinding::create([
                'project_id' => $project->id,
                'severity' => 'info',
                'category' => 'exposure_consistency',
                'message' => "Consistency review over {$photos->count()} photo(s) in scope [{$scope}].",
                'details' => ['scope' => $scope, 'focus' => $focus, 'checked_at' => now()->toISOString()],
            ]);
        }

        // Flag photos missing camera metadata (typical of placeholder seeds).
        $missing = $photos->filter(fn ($p) => blank($p->camera_model) && blank($p->iso));
        $missing->each(function ($p) use (&$created) {
            $created[] = QaFinding::create([
                'project_id' => $p->project_id,
                'photo_id' => $p->id,
                'severity' => 'warning',
                'category' => 'metadata_completeness',
                'message' => "Photo [{$p->filename}] lacks camera metadata (model/ISO); unable to fully assess consistency.",
            ]);
        });

        $this->audit->record(
            $request,
            $project,
            $request->user(),
            'run_consistency_review',
            Domain::AUTHORITY_PROPOSE,
            $validated,
            ['created_findings' => count($created), 'photos_checked' => $photos->count()],
        );

        return response()->json([
            'project_id' => $project->id,
            'scope' => $scope,
            'photos_checked' => $photos->count(),
            'created_findings' => collect($created)->map->only(['id', 'severity', 'category', 'message', 'photo_id'])->values(),
        ], 201);
    }
}
