<?php

namespace App\Http\Controllers\Webmcp;

use App\Domain\Domain;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAgentConversationMessageRequest;
use App\Models\AgentConversationMessage;
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

    /** get_agent_conversation */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $data = $this->conversation->forProject(
            $project,
            isset($validated['after']) ? (int) $validated['after'] : null,
            isset($validated['limit']) ? (int) $validated['limit'] : AgentConversationService::DEFAULT_LIMIT,
        );

        $this->audit->record(
            $request,
            $project,
            $request->user(),
            'get_agent_conversation',
            Domain::AUTHORITY_READ,
            $validated,
            [
                'message_count' => count($data['messages']),
                'latest_id' => $data['latest_id'],
            ],
        );

        return response()->json($data);
    }

    /** reply_to_agent_conversation */
    public function reply(StoreAgentConversationMessageRequest $request, Project $project): JsonResponse
    {
        $this->authorize('replyAsAgent', $project);

        /** @var User $user */
        $user = $request->user();
        $result = $this->conversation->send(
            $project,
            $user,
            $request->validated('body'),
            $request->validated('client_message_id'),
            AgentConversationMessage::ORIGIN_EXTERNAL,
        );

        $this->audit->record(
            $request,
            $project,
            $user,
            'reply_to_agent_conversation',
            Domain::AUTHORITY_PROPOSE,
            [
                'client_message_id' => $request->validated('client_message_id'),
                'body_length' => mb_strlen($request->validated('body')),
            ],
            [
                'message_id' => $result['message']['id'],
                'deduplicated' => $result['deduplicated'],
            ],
        );

        return response()->json($result, $result['deduplicated'] ? 200 : 201);
    }
}
