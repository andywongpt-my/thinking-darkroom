<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Models\Project;
use App\Models\QaFinding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 4 — HUMAN-ONLY QA finding actions.
 *
 * The agent may analyze drift and explain it; the photographer retains final
 * authority over every finding: acknowledge (keep on the record, noted) or
 * dismiss (e.g. an intentional creative outlier). These actions deliberately
 * DO NOT exist in the WebMCP tool catalog, and agent-flagged accounts are
 * hard-blocked at the controller, mirroring proposal review.
 *
 * Deliberately NO correction-execution here: a "request correction" is
 * expressed by the photographer asking for a new proposal (modify flow), so
 * there is no force_retouch-style tool to create.
 */
class QaFindingReviewController extends Controller
{
    public function respond(Request $request, Project $project, QaFinding $finding): JsonResponse
    {
        $this->authorizePhotographer($request, $project, $finding);

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:acknowledge,dismiss'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        // Persist the photographer's rationale (Sol P2-7): the note is part
        // of the QA audit trail, not a transient request field.
        $finding->forceFill([
            'status' => $validated['action'] === 'acknowledge' ? 'acknowledged' : 'resolved',
            'details' => array_merge((array) ($finding->details ?? []), [
                'review' => [
                    'action' => $validated['action'],
                    'note' => $validated['note'] ?? null,
                    'reviewer_id' => $request->user()->id,
                    'reviewed_at' => now()->toISOString(),
                ],
            ]),
        ])->save();

        return response()->json([
            'finding' => $finding->only(['id', 'status', 'severity', 'category', 'message']),
            'action' => $validated['action'],
            'review' => $finding->details['review'] ?? null,
        ]);
    }

    private function authorizePhotographer(Request $request, Project $project, QaFinding $finding): void
    {
        $user = $request->user();

        if ($finding->project_id !== $project->id) {
            abort(404);
        }

        $member = $project->members()->where('user_id', $user->id)->first();
        if (! $member) {
            abort(403);
        }

        if ($user->isAgent()) {
            Log::warning('webmcp authority violation blocked', [
                'user' => $user->id,
                'action' => 'qa-findings.respond',
                'finding' => $finding->id,
            ]);
            abort(403, 'Agent accounts cannot exercise photographer authority.');
        }

        if (! in_array($member->pivot->role, [Domain::ROLE_OWNER, Domain::ROLE_PHOTOGRAPHER], true)) {
            abort(403, 'Only the photographer can respond to QA findings.');
        }
    }
}
