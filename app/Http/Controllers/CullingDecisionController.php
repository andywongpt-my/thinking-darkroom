<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Models\Photo;
use App\Models\PhotographerDecision;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 3 — HUMAN-ONLY culling decision endpoints.
 *
 * The photographer (and only the photographer) can:
 *   - set their own decision on a photo (keep | review | reject)
 *   - OVERRIDE the agent's recommendation with an explicit note
 *
 * These deliberately DO NOT exist in the WebMCP tool catalog and are guarded
 * by the same hard boundary as proposal review: a user flagged `is_agent` can
 * never exercise photographer authority — not via tools, not via raw HTTP.
 *
 * The decision is persisted in photographer_decisions (photo-level, no
 * Proposal row needed) and the photo's selection_state is updated through
 * the same Domain vocabulary the photographer UI uses everywhere else.
 */
class CullingDecisionController extends Controller
{
    /**
     * Record the photographer's decision on one photo.
     *
     * body: { decision: keep|review|reject, note?: string, override?: bool }
     */
    public function decide(Request $request, Project $project, Photo $photo): JsonResponse
    {
        $this->authorizePhotographer($request, $project, $photo);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:'.implode(',', Domain::CULLING_DECISIONS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'override' => ['sometimes', 'boolean'],
        ]);

        $decision = DB::transaction(function () use ($request, $project, $photo, $validated) {
            // Decision row + photo state change commit or roll back together
            // (Sol P2-6): the photographer's history must never disagree with
            // the workspace's current selection state.
            // firstOrFail (AGY L-1): if the photo is deleted concurrently after
            // authorization, return a proper 404 instead of a fatal TypeError.
            $photo = Photo::whereKey($photo->id)->lockForUpdate()->firstOrFail();

            $decision = PhotographerDecision::create([
                'project_id' => $project->id,
                'photo_id' => $photo->id,
                'photographer_id' => $request->user()->id,
                'decision' => $validated['decision'],
                'note' => $validated['note'] ?? null,
                'modifications' => [
                    'override' => (bool) ($validated['override'] ?? false),
                    'surface' => 'culling_ui',
                ],
            ]);

            // The photographer's explicit decision is the ONE thing that changes
            // selection state directly (agents can only propose).
            $photo->forceFill([
                'selection_state' => match ($validated['decision']) {
                    'keep' => Domain::SELECTION_SELECTED,
                    'reject' => Domain::SELECTION_CULLED,
                    default => Domain::SELECTION_UNREVIEWED,
                },
            ])->save();

            return $decision;
        });

        return response()->json([
            'decision' => [
                'id' => $decision->id,
                'project_id' => $project->id,
                'photo_id' => $photo->id,
                'decision' => $decision->decision,
                'note' => $decision->note,
                'override' => (bool) ($validated['override'] ?? false),
                'photographer' => ['id' => $request->user()->id, 'name' => $request->user()->name],
                'decided_at' => $decision->created_at?->toISOString(),
            ],
            'photo' => [
                'id' => $photo->id,
                'selection_state' => $photo->fresh()->selection_state,
            ],
        ], 201);
    }

    private function authorizePhotographer(Request $request, Project $project, Photo $photo): void
    {
        $user = $request->user();

        if ($photo->project_id !== $project->id) {
            abort(404);
        }

        $member = $project->members()->where('user_id', $user->id)->first();
        if (! $member) {
            abort(403);
        }

        // HARD BOUNDARY: an agent account can never exercise this endpoint.
        if ($user->isAgent()) {
            Log::warning('webmcp authority violation blocked', [
                'user' => $user->id,
                'action' => 'culling.photographer-decide',
                'photo' => $photo->id,
            ]);
            abort(403, 'Agent accounts cannot exercise photographer authority.');
        }

        if (! in_array($member->pivot->role, [Domain::ROLE_OWNER, Domain::ROLE_PHOTOGRAPHER], true)) {
            abort(403, 'Only the photographer can decide culling outcomes.');
        }
    }
}
