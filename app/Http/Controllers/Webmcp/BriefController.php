<?php

namespace App\Http\Controllers\Webmcp;

use App\Domain\Domain;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ToolCallAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BriefController extends Controller
{
    public function __construct(private readonly ToolCallAuditService $audit) {}

    /** get_creative_brief */
    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $brief = $project->brief;

        $this->audit->record(
            $request,
            $project,
            $request->user(),
            'get_creative_brief',
            Domain::AUTHORITY_READ,
            ['project_id' => $project->id],
            ['brief_id' => $brief?->id],
        );

        if (! $brief) {
            return response()->json(['project_id' => $project->id, 'brief' => null]);
        }

        return response()->json([
            'project_id' => $project->id,
            'brief' => [
                'id' => $brief->id,
                'client' => $brief->client,
                'shoot_date' => $brief->shoot_date?->toDateString(),
                'location' => $brief->location,
                'creative_direction' => $brief->creative_direction,
                'tonality_notes' => $brief->tonality_notes,
                'deliverables' => $brief->deliverables,
                'status' => $brief->status,
            ],
        ]);
    }
}
