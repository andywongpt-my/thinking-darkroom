<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\AgentActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentActivityController extends Controller
{
    public function __construct(private readonly AgentActivityService $activity) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'before' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->activity->forProject(
            $project,
            isset($validated['after']) ? (int) $validated['after'] : null,
            isset($validated['limit']) ? (int) $validated['limit'] : AgentActivityService::DEFAULT_LIMIT,
            isset($validated['before']) ? (int) $validated['before'] : null,
        ));
    }
}
