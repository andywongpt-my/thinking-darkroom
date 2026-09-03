<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAgentConversationMessageRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\AgentConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentConversationController extends Controller
{
    public function __construct(private readonly AgentConversationService $conversation) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'before' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->conversation->forProject(
            $project,
            isset($validated['after']) ? (int) $validated['after'] : null,
            isset($validated['limit']) ? (int) $validated['limit'] : AgentConversationService::DEFAULT_LIMIT,
            isset($validated['before']) ? (int) $validated['before'] : null,
        ));
    }

    public function store(StoreAgentConversationMessageRequest $request, Project $project): JsonResponse
    {
        $this->authorize('message', $project);

        /** @var User $user */
        $user = $request->user();
        $result = $this->conversation->send(
            $project,
            $user,
            $request->validated('body'),
            $request->validated('client_message_id'),
        );

        return response()->json($result, $result['deduplicated'] ? 200 : 201);
    }
}
