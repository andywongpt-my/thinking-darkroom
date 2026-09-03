<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Models\CreativeMemory;
use App\Models\Project;
use App\Models\Proposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 4 — LEARN: HUMAN-ONLY creative memory endpoints.
 *
 * The photographer (and only the photographer) persists explicit decision
 * lessons ("less warm", "keep this frame darker", "preserve grain"). These
 * are read by FUTURE agent proposals as durable deterministic context —
 * they are NOT ML personalization, just explicit decision history.
 *
 * These endpoints deliberately DO NOT exist in the WebMCP tool catalog and
 * are guarded like every other photographer-authority surface: an account
 * flagged `is_agent` can never write creative memory.
 */
class CreativeMemoryController extends Controller
{
    /**
     * Persist one photographer-authored creative memory.
     * body: { lesson: string, kind?: lesson|preference|override,
     *         proposal_id?: number }
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorizePhotographer($request, $project);

        $validated = $request->validate([
            'lesson' => ['required', 'string', 'min:3', 'max:500'],
            'kind' => ['sometimes', 'string', 'in:lesson,preference,override'],
            'proposal_id' => ['sometimes', 'nullable', 'integer', 'exists:proposals,id'],
            'context' => ['sometimes', 'array'],
        ]);

        if (! empty($validated['proposal_id'])) {
            $belongs = Proposal::where('id', $validated['proposal_id'])
                ->where('project_id', $project->id)->exists();
            if (! $belongs) {
                abort(404);
            }
        }

        $memory = CreativeMemory::create([
            'project_id' => $project->id,
            'photographer_id' => $request->user()->id,
            'proposal_id' => $validated['proposal_id'] ?? null,
            'kind' => $validated['kind'] ?? 'lesson',
            'created_by' => $request->user()->id,
            'lesson' => $validated['lesson'],
            'context' => $validated['context'] ?? null,
        ]);

        return response()->json(['memory' => [
            'id' => $memory->id,
            'kind' => $memory->kind,
            'lesson' => $memory->lesson,
            // Contract: a STRING name — same shape as the workspace page props
            // and the index() endpoint. A {id,name} relation object here was
            // rendered as a React child by the optimistic prepend in
            // Workspace.tsx (live E2E finding: Minified React error #31,
            // "object with keys {id,name}").
            'photographer' => $request->user()->name,
            'created_at' => $memory->created_at?->toISOString(),
        ]], 201);
    }

    /** List this project's creative memories (photographer or member agent). */
    public function index(Request $request, Project $project): JsonResponse
    {
        $member = $project->members()->where('user_id', $request->user()->id)->first();
        if (! $member) {
            abort(403);
        }

        $memories = $project->creativeMemories()
            ->with('photographer:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'kind' => $m->kind,
                'lesson' => $m->lesson,
                'photographer' => $m->photographer?->name,
                'created_at' => $m->created_at?->toISOString(),
            ])
            ->values();

        return response()->json([
            'project_id' => $project->id,
            'memories' => $memories,
        ]);
    }

    /** Update one creative memory lesson (photographer authority). */
    public function update(Request $request, Project $project, CreativeMemory $memory): JsonResponse
    {
        $this->authorizePhotographer($request, $project);

        if ($memory->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'lesson' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $memory->update(['lesson' => $validated['lesson']]);

        return response()->json(['memory' => [
            'id' => $memory->id,
            'kind' => $memory->kind,
            'lesson' => $memory->lesson,
            'photographer' => $memory->photographer?->name,
            'created_at' => $memory->created_at?->toISOString(),
        ]]);
    }

    /** Delete one creative memory (photographer authority, hard agent boundary). */
    public function destroy(Request $request, Project $project, CreativeMemory $memory): JsonResponse
    {
        $this->authorizePhotographer($request, $project);

        // Cross-project guard: the memory row must belong to the route project.
        if ($memory->project_id !== $project->id) {
            abort(404);
        }

        $memory->delete();

        return response()->json(['deleted' => true, 'memory_id' => $memory->id]);
    }

    private function authorizePhotographer(Request $request, Project $project): void
    {
        $user = $request->user();

        $member = $project->members()->where('user_id', $user->id)->first();
        if (! $member) {
            abort(403);
        }

        // HARD BOUNDARY: an agent account can never write creative memory.
        if ($user->isAgent()) {
            Log::warning('webmcp authority violation blocked', [
                'user' => $user->id,
                'action' => 'creative-memory.store',
            ]);
            abort(403, 'Agent accounts cannot exercise photographer authority.');
        }

        if (! in_array($member->pivot->role, [Domain::ROLE_OWNER, Domain::ROLE_PHOTOGRAPHER], true)) {
            abort(403, 'Only the photographer can persist creative memory.');
        }
    }
}
