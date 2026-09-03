<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\AgentTurnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentConversationTurnController extends Controller
{
    public function __construct(private readonly AgentTurnService $turns) {}

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('message', $project);

        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validate([
            'trigger_id' => ['required', 'integer', 'min:1'],
        ]);
        $trigger = $project->agentConversationMessages()
            ->whereKey((int) $validated['trigger_id'])
            ->firstOrFail();

        // A turn is a follow-up to the authenticated photographer's own
        // message. This prevents an agent account from asking the server agent
        // to answer another member's message.
        abort_unless($trigger->user_id === $actor->id, 403);

        return response()->json($this->turns->run($project, $trigger));
    }
}
