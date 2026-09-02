<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Models\CreativeConcept;
use App\Models\Project;
use App\Services\CreativeRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * HUMAN-ONLY Creative Room review flows.
 *
 * These endpoints deliberately DO NOT exist in the WebMCP tool catalog. They
 * are protected by a hard guard: a user flagged `is_agent` can never exercise
 * creative authority. Adoption / rejection / exploration of a creative
 * direction is a HUMAN action, never an agent tool.
 */
class CreativeRoomReviewController extends Controller
{
    public function __construct(private readonly CreativeRoomService $creative) {}

    /** OPEN BRAINSTORM — photographer enters freeform creative thinking. */
    public function openBrainstorm(Request $request, Project $project): JsonResponse
    {
        $this->authorizePhotographerForBrainstorm($request, $project);

        $validated = $request->validate([
            'input' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $session = $this->creative->openBrainstorm($project, $request->user(), $validated['input']);
        } catch (ValidationException $e) {
            throw $e;
        }

        return response()->json([
            'project_id' => $project->id,
            'brainstorm' => [
                'id' => $session->id,
                'input' => $session->input,
                'status' => $session->status,
                'created_at' => $session->created_at->toISOString(),
            ],
        ], 201);
    }

    /** EXPLORE — mark a concept as the direction the photographer is exploring. */
    public function explore(Request $request, Project $project, CreativeConcept $concept): JsonResponse
    {
        $this->authorizePhotographer($request, $project, $concept, 'explore');

        try {
            $concept = $this->creative->exploreConcept($project, $request->user(), $concept);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['concept' => $this->payload($concept, $project)]);
    }

    /** REJECT — mark rejected, preserving history. */
    public function reject(Request $request, Project $project, CreativeConcept $concept): JsonResponse
    {
        $this->authorizePhotographer($request, $project, $concept, 'reject');

        $validated = $request->validate(['note' => ['sometimes', 'string', 'max:2000']]);

        try {
            $concept = $this->creative->rejectConcept(
                $project,
                $request->user(),
                $concept,
                $validated['note'] ?? null,
            );
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['concept' => $this->payload($concept, $project)]);
    }

    /** ADOPT — the photographer commits a concept as the current creative direction. */
    public function adopt(Request $request, Project $project, CreativeConcept $concept): JsonResponse
    {
        $this->authorizePhotographer($request, $project, $concept, 'adopt');

        $validated = $request->validate(['note' => ['sometimes', 'string', 'max:2000']]);

        try {
            $concept = $this->creative->adoptConcept(
                $project,
                $request->user(),
                $concept,
                $validated['note'] ?? null,
            );
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['concept' => $this->payload($concept, $project)]);
    }

    /** Brainstorm input is photographer-authored; agent accounts cannot write it. */
    private function authorizePhotographerForBrainstorm(Request $request, Project $project): void
    {
        $user = $request->user();

        $member = $project->members()->where('user_id', $user->id)->first();
        if (! $member) {
            abort(403);
        }

        if ($user->isAgent()) {
            abort(403, 'Agent accounts cannot author brainstorm input.');
        }

        if (! in_array($member->pivot->role, [Domain::ROLE_OWNER, Domain::ROLE_PHOTOGRAPHER], true)) {
            abort(403, 'Only the photographer can author brainstorm input.');
        }
    }

    private function authorizePhotographer(Request $request, Project $project, CreativeConcept $concept, string $action): void
    {
        $user = $request->user();

        if ($concept->project_id !== $project->id) {
            abort(404);
        }

        $member = $project->members()->where('user_id', $user->id)->first();
        if (! $member) {
            abort(403);
        }

        // HARD BOUNDARY: an agent account can never adopt/explore/reject.
        if ($user->isAgent()) {
            Log::warning('webmcp creative authority violation blocked', [
                'user' => $user->id,
                'action' => $action,
                'concept' => $concept->id,
            ]);
            abort(403, 'Agent accounts cannot exercise creative authority.');
        }

        if (! in_array($member->pivot->role, [Domain::ROLE_OWNER, Domain::ROLE_PHOTOGRAPHER], true)) {
            abort(403, 'Only the photographer can make creative decisions.');
        }
    }

    /** @return array<string, mixed> */
    private function payload(CreativeConcept $concept, Project $project): array
    {
        $concept->load('items');

        return [
            'id' => $concept->id,
            'project_id' => $concept->project_id,
            'title' => $concept->title,
            'summary' => $concept->summary,
            'content' => $concept->content,
            'status' => $concept->status,
            'parent_concept_id' => $concept->parent_concept_id,
            'lineage_basis' => $concept->lineage_basis,
            'adopted_at' => $concept->adopted_at?->toISOString(),
            'items' => $concept->items->map(fn ($i) => [
                'dimension' => $i->dimension,
                'label' => $i->label,
                'value' => $i->value,
                'source' => $i->source,
            ])->values(),
            'current_creative_direction' => $project->currentCreativeDirection()?->id,
        ];
    }
}
