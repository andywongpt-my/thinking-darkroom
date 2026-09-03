<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\WebmcpToolCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Development WebMCP diagnostics — reflects which tools the server would
 * currently register for the project. The browser registry derives from the
 * same condition so the panel reflects real lifecycle state.
 */
class WebmcpDiagnosticsController extends Controller
{
    public function tools(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        // C12: this is a development/integration diagnostics surface. Human
        // members get the workspace UI; the raw tool-registry JSON is for
        // agent accounts (and local/CI smoke), not for photographers.
        if (! $request->user()->isAgent()) {
            abort(404);
        }

        return response()->json([
            'project_id' => $project->id,
            'webmcp_available' => true,
            'eligible_approval' => $project->hasEligibleExecutableProposal(),
            'tools' => WebmcpToolCatalog::availableFor($project),
        ]);
    }
}
