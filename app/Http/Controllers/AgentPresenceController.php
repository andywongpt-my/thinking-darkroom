<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\AgentPresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentPresenceController extends Controller
{
    public function show(Project $project, AgentPresenceService $presence): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($presence->forProject($project));
    }

    public function heartbeat(Request $request, Project $project, AgentPresenceService $presence): JsonResponse
    {
        $this->authorize('heartbeat', $project);

        /** @var User $user */
        $user = $request->user();

        return response()->json($presence->heartbeat($project, $user));
    }
}
