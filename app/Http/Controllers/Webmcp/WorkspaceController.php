<?php

namespace App\Http\Controllers\Webmcp;

use App\Domain\Domain;
use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\Project;
use App\Support\WebmcpToolCatalog;
use App\Services\ToolCallAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * READ authority tools. Every response is also written to the agent audit
 * trail and rendered in the Agent Activity panel.
 */
class WorkspaceController extends Controller
{
    public function __construct(private readonly ToolCallAuditService $audit) {}

    /** get_workspace_context */
    public function context(Request $request, Project $project): JsonResponse
    {
        $start = hrtime(true);

        $brief = $project->brief;
        $data = [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'description' => $project->description,
            ],
            'brief' => $brief ? [
                'id' => $brief->id,
                'client' => $brief->client,
                'shoot_date' => $brief->shoot_date?->toDateString(),
                'location' => $brief->location,
                'creative_direction' => $brief->creative_direction,
                'tonality_notes' => $brief->tonality_notes,
                'deliverables' => $brief->deliverables,
            ] : null,
            'counts' => [
                'total' => $project->photos()->count(),
                'selected' => $project->photos()->where('selection_state', Domain::SELECTION_SELECTED)->count(),
                'culled' => $project->photos()->where('selection_state', Domain::SELECTION_CULLED)->count(),
                'unreviewed' => $project->photos()->where('selection_state', Domain::SELECTION_UNREVIEWED)->count(),
            ],
            'proposals' => [
                'pending' => $project->proposals()->where('status', Domain::STATE_PENDING_REVIEW)->count(),
                'approved_unexecuted' => $project->executableProposals()->count(),
            ],
            'qa' => ['open' => $project->findings()->where('status', 'open')->count()],
            'webmcp_available' => true,
            'generated_at' => now()->toISOString(),
        ];

        $this->audit->record(
            $request,
            $project,
            $request->user(),
            'get_workspace_context',
            Domain::AUTHORITY_READ,
            [],
            ['project' => $project->id, 'counts' => $data['counts']],
        );

        return response()->json($data);
    }
}
