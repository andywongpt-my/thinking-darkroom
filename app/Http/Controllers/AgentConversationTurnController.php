<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\AgentTurnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            'client_opt_in' => ['sometimes', 'boolean'],
        ]);

        if (($validated['client_opt_in'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'client_opt_in' => 'The built-in offline assistant is opt-in; pass client_opt_in=true',
            ]);
        }

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
