<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Models\Project;
use App\Models\Proposal;
use App\Services\ProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * HUMAN-ONLY review flows.
 *
 * These endpoints deliberately DO NOT exist in the WebMCP tool catalog and
 * are protected by a hard guard: a user flagged `is_agent` can never exercise
 * photographer authority. Approval is a human action, never an agent tool.
 */
class PhotographerReviewController extends Controller
{
    public function __construct(private readonly ProposalService $proposals) {}

    public function approve(Request $request, Project $project, Proposal $proposal): JsonResponse
    {
        $this->authorizePhotographer($request, $project, $proposal);

        try {
            $proposal = $this->proposals->approve($proposal, $request->user());
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['proposal' => $proposal->load('items.photo')->only([
            'id', 'project_id', 'type', 'status', 'summary', 'reviewed_at', 'executed_at',
        ])]);
    }

    public function reject(Request $request, Project $project, Proposal $proposal): JsonResponse
    {
        $this->authorizePhotographer($request, $project, $proposal);

        $validated = $request->validate(['note' => ['sometimes', 'string', 'max:2000']]);
        try {
            $proposal = $this->proposals->reject($proposal, $request->user(), $validated['note'] ?? null);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['proposal' => $proposal->only([
            'id', 'project_id', 'type', 'status', 'summary', 'reviewed_at', 'executed_at',
        ])]);
    }

    public function modify(Request $request, Project $project, Proposal $proposal): JsonResponse
    {
        $this->authorizePhotographer($request, $project, $proposal);

        $validated = $request->validate([
            'note' => ['sometimes', 'string', 'max:2000'],
            'modifications' => ['sometimes', 'array'],
            // Sprint 4 — photographer-edited adjustment values. Shape-checked
            // here; value validation happens against the adjustment vocabulary
            // in the superseding proposal's lifecycle + applicator.
            'modifications.adjustments' => ['sometimes', 'array', 'max:12'],
            'modifications.adjustments.*' => ['numeric'],
            'modifications.summary' => ['sometimes', 'string', 'max:2000'],
        ]);

        try {
            $draft = $this->proposals->requestModification(
                $proposal,
                $request->user(),
                $validated['note'] ?? null,
                $validated['modifications'] ?? null,
            );
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        // The agent can pick up the draft on the next proposal cycle; a
        // photographer-edited retouch supersede lands directly pending_review.
        return response()->json([
            'proposal' => $proposal->only(['id', 'project_id', 'type', 'status']),
            'superseding_draft' => $draft->only(['id', 'project_id', 'type', 'status', 'summary']),
        ]);
    }

    private function authorizePhotographer(Request $request, Project $project, Proposal $proposal): void
    {
        $user = $request->user();

        if ($proposal->project_id !== $project->id) {
            abort(404);
        }

        $member = $project->members()->where('user_id', $user->id)->first();
        if (! $member) {
            abort(403);
        }

        // HARD BOUNDARY: an agent account can never approve.
        if ($user->isAgent()) {
            Log::warning('webmcp authority violation blocked', [
                'user' => $user->id,
                'action' => $request->route()->getActionMethod(),
                'proposal' => $proposal->id,
            ]);
            abort(403, 'Agent accounts cannot exercise photographer authority.');
        }

        if (! in_array($member->pivot->role, [Domain::ROLE_OWNER, Domain::ROLE_PHOTOGRAPHER], true)) {
            abort(403, 'Only the photographer can review proposals.');
        }
    }
}
