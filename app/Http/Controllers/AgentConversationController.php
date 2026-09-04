<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Http\Requests\StoreAgentConversationMessageRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\AgentConversationService;
use App\Services\ToolCallAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentConversationController extends Controller
{
    public function __construct(
        private readonly AgentConversationService $conversation,
        private readonly ToolCallAuditService $audit,
    ) {}

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

        // An agent account posting through this web route (allowed by
        // ProjectPolicy::message) must leave the same immutable audit trail
        // as the audited WebMCP reply endpoint — otherwise an agent could
        // write conversation history with zero trace in agent_tool_calls.
        // Human photographers keep the endpoint audit-free.
        if ($user->isAgent()) {
            $this->audit->record(
                $request,
                $project,
                $user,
                'agent_conversation_web_store',
                Domain::AUTHORITY_PROPOSE,
                [
                    'client_message_id' => $request->validated('client_message_id'),
                    'body_length' => mb_strlen((string) $request->validated('body')),
                ],
                [
                    'message_id' => $result['message']['id'],
                    'deduplicated' => $result['deduplicated'],
                ],
            );
        }

        return response()->json($result, $result['deduplicated'] ? 200 : 201);
    }
}
