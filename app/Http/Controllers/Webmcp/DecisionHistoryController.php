<?php

namespace App\Http\Controllers\Webmcp;

use App\Domain\Domain;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ToolCallAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DecisionHistoryController extends Controller
{
    public function __construct(private readonly ToolCallAuditService $audit) {}

    /** get_decision_history */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $decisions = $project->decisions()
            ->with('proposal', 'photographer:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'proposal_id' => $d->proposal_id,
                'proposal_type' => $d->proposal?->type,
                'proposal_status' => $d->proposal?->status,
                'photographer' => $d->photographer?->name,
                'decision' => $d->decision,
                'note' => $d->note,
                'modifications' => $d->modifications,
                'decided_at' => $d->created_at->toISOString(),
            ])
            ->values();

        $proposals = $project->proposals()
            ->withCount('items')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'type' => $p->type,
                'status' => $p->status,
                'summary' => $p->summary,
                'items_count' => $p->items_count,
                'reviewed_at' => $p->reviewed_at?->toISOString(),
                'executed_at' => $p->executed_at?->toISOString(),
            ])
            ->values();

        $this->audit->record(
            $request,
            $project,
            $request->user(),
            'get_decision_history',
            Domain::AUTHORITY_READ,
            ['project_id' => $project->id],
            ['decisions' => $decisions->count(), 'proposals' => $proposals->count()],
        );

        return response()->json([
            'project_id' => $project->id,
            'decisions' => $decisions,
            'proposals' => $proposals,
        ]);
    }
}
