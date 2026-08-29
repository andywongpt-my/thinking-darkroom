<?php

namespace App\Http\Controllers\Webmcp;

use App\Domain\Domain;
use App\Http\Controllers\Controller;
use App\Models\BrainstormSession;
use App\Models\CreativeBrief;
use App\Models\CreativeConcept;
use App\Models\Project;
use App\Services\CreativeRoomService;
use App\Services\ToolCallAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 2 — Creative Room WebMCP tools.
 *
 * READ    : get_brainstorm_context, get_creative_direction, list_concepts, get_concept
 * PROPOSE : propose_concepts, propose_concept_revision, propose_concept_merge, propose_creative_brief
 *
 * There is deliberately NO adoption/approve/final-direction tool here: adopting
 * a creative direction is a HUMAN action exercised only through the UI
 * (CreativeRoomReviewController). Server-side, every PROPOSE method only ever
 * creates concepts in a non-adopted status.
 */
class CreativeRoomController extends Controller
{
    public function __construct(
        private readonly CreativeRoomService $creative,
        private readonly ToolCallAuditService $audit,
    ) {}

    /* ------------------------------- READ tools ------------------------------- */

    /** get_brainstorm_context */
    public function brainstormContext(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $session = $project->brainstormSessions()->latest('id')->first();
        $direction = $this->creative->structuredIntentFor($project);

        $this->audit->record(
            $request, $project, $request->user(),
            'get_brainstorm_context', Domain::AUTHORITY_READ,
            ['project_id' => $project->id],
            ['session_id' => $session?->id, 'has_direction' => $direction !== null],
        );

        return response()->json([
            'project_id' => $project->id,
            'brainstorm' => $session ? [
                'id' => $session->id,
                'input' => $session->input,
                'status' => $session->status,
                'photographer' => $session->photographer?->name,
                'created_at' => $session->created_at->toISOString(),
            ] : null,
            'creative_direction' => $direction,
        ]);
    }

    /** get_creative_direction — the adopted direction + derived structured brief. */
    public function creativeDirection(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $direction = $this->creative->structuredIntentFor($project);

        $this->audit->record(
            $request, $project, $request->user(),
            'get_creative_direction', Domain::AUTHORITY_READ,
            ['project_id' => $project->id],
            ['has_direction' => $direction !== null],
        );

        return response()->json($direction ?? [
            'project_id' => $project->id,
            'has_direction' => false,
            'adopted_concept' => null,
            'brief' => null,
            'intent' => [],
        ]);
    }

    /** list_concepts */
    public function concepts(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $concepts = $project->creativeConcepts()
            ->with('items')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($c) => $this->conceptPayload($c))
            ->values();

        $this->audit->record(
            $request, $project, $request->user(),
            'list_concepts', Domain::AUTHORITY_READ,
            ['project_id' => $project->id],
            ['concepts' => $concepts->count()],
        );

        return response()->json([
            'project_id' => $project->id,
            'concepts' => $concepts,
        ]);
    }

    /** get_concept */
    public function concept(Request $request, Project $project, CreativeConcept $concept): JsonResponse
    {
        $this->authorize('view', $project);
        if ($concept->project_id !== $project->id) {
            abort(404, 'Concept does not belong to this project.');
        }

        $this->audit->record(
            $request, $project, $request->user(),
            'get_concept', Domain::AUTHORITY_READ,
            ['project_id' => $project->id, 'concept_id' => $concept->id],
            ['concept_title' => $concept->title, 'status' => $concept->status],
        );

        return response()->json([
            'project_id' => $project->id,
            'concept' => $this->conceptPayload($concept->load('items')),
        ]);
    }

    /* ------------------------------ PROPOSE tools ------------------------------ */

    /** propose_concepts — up to 3 structured concepts; adoption is human-only. */
    public function proposeConcepts(Request $request, Project $project): JsonResponse
    {
        $this->authorize('propose', $project);

        $validated = $request->validate([
            'brainstorm_session_id' => ['sometimes', 'integer', 'exists:brainstorm_sessions,id'],
            'concepts' => ['required', 'array', 'min:1', 'max:3'],
            'concepts.*.title' => ['required', 'string', 'max:255'],
            'concepts.*.summary' => ['sometimes', 'string', 'max:2000'],
            'concepts.*.content' => ['required', 'array'],
            'concepts.*.items' => ['sometimes', 'array'],
            'concepts.*.items.*.dimension' => ['sometimes', 'string', 'max:48'],
            'concepts.*.items.*.label' => ['sometimes', 'string', 'max:255'],
            'concepts.*.items.*.value' => ['sometimes', 'string', 'max:2000'],
        ]);

        $session = null;
        if (! empty($validated['brainstorm_session_id'])) {
            $session = BrainstormSession::find($validated['brainstorm_session_id']);
            if (! $session || $session->project_id !== $project->id) {
                throw ValidationException::withMessages([
                    'brainstorm_session_id' => 'Brainstorm session does not belong to this project.',
                ]);
            }
        }

        $concepts = [];
        try {
            DB::transaction(function () use ($request, $project, $session, $validated, &$concepts) {
                foreach ($validated['concepts'] as $i => $c) {
                    $concepts[] = $this->creative->proposeConcept(
                        $project,
                        $request->user(),
                        $session,
                        $c['title'],
                        $c['summary'] ?? null,
                        $c['content'],
                        $c['items'] ?? null,
                    );
                }
            });
        } catch (\Throwable $e) {
            $this->audit->record(
                $request, $project, $request->user(),
                'propose_concepts', Domain::AUTHORITY_PROPOSE,
                ['concepts' => count($validated['concepts'] ?? [])],
                ['error' => $e->getMessage()],
                Domain::RESULT_ERROR,
            );

            return response()->json(['error' => 'Concept proposal failed.'], 422);
        }

        $payloads = collect($concepts)->map(fn ($c) => $this->conceptPayload($c->load('items')))->values();

        $this->audit->record(
            $request, $project, $request->user(),
            'propose_concepts', Domain::AUTHORITY_PROPOSE,
            ['concept_ids' => collect($concepts)->pluck('id')->all()],
            ['concepts' => $payloads->count(), 'status' => Domain::CONCEPT_STATUS_PROPOSED],
        );

        return response()->json([
            'project_id' => $project->id,
            'concepts' => $payloads,
        ], 201);
    }

    /** propose_concept_revision — child of an existing concept, lineage preserved. */
    public function proposeConceptRevision(Request $request, Project $project, CreativeConcept $concept): JsonResponse
    {
        $this->authorize('propose', $project);
        if ($concept->project_id !== $project->id) {
            abort(404, 'Concept does not belong to this project.');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['sometimes', 'string', 'max:2000'],
            'content' => ['required', 'array'],
            'items' => ['sometimes', 'array'],
        ]);

        $child = $this->creative->proposeConceptRevision(
            $project,
            $request->user(),
            $concept,
            $validated['title'],
            $validated['summary'] ?? null,
            $validated['content'],
            $validated['items'] ?? null,
        );

        $this->audit->record(
            $request, $project, $request->user(),
            'propose_concept_revision', Domain::AUTHORITY_PROPOSE,
            ['source_concept_id' => $concept->id],
            ['child_concept_id' => $child->id, 'status' => $child->status],
        );

        return response()->json([
            'project_id' => $project->id,
            'concept' => $this->conceptPayload($child->load('items')),
        ], 201);
    }

    /** propose_concept_merge — combine two+ concepts into a new merged concept. */
    public function proposeConceptMerge(Request $request, Project $project): JsonResponse
    {
        $this->authorize('propose', $project);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['sometimes', 'string', 'max:2000'],
            'content' => ['required', 'array'],
            'items' => ['sometimes', 'array'],
            'sources' => ['required', 'array', 'min:2'],
            'sources.*.concept_id' => ['required', 'integer'],
            'sources.*.note' => ['sometimes', 'string', 'max:2000'],
        ]);

        try {
            $merged = $this->creative->proposeConceptMerge(
                $project,
                $request->user(),
                $validated['sources'],
                $validated['title'],
                $validated['summary'] ?? null,
                $validated['content'],
                $validated['items'] ?? null,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'sources' => $e->getMessage(),
            ]);
        }

        $this->audit->record(
            $request, $project, $request->user(),
            'propose_concept_merge', Domain::AUTHORITY_PROPOSE,
            ['sources' => array_column($validated['sources'], 'concept_id')],
            ['merged_concept_id' => $merged->id, 'status' => $merged->status],
        );

        return response()->json([
            'project_id' => $project->id,
            'concept' => $this->conceptPayload($merged->load('items')),
        ], 201);
    }

    /** propose_creative_brief — persisted PROPOSAL only; never activates. */
    public function proposeCreativeBrief(Request $request, Project $project): JsonResponse
    {
        $this->authorize('propose', $project);

        $validated = $request->validate([
            'source_concept_id' => ['sometimes', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'payload' => ['required', 'array'],
        ]);

        $source = null;
        if (! empty($validated['source_concept_id'])) {
            /** @var CreativeConcept|null $source */
            $source = $project->creativeConcepts()->find($validated['source_concept_id']);
            if (! $source) {
                throw ValidationException::withMessages([
                    'source_concept_id' => 'Source concept does not belong to this project.',
                ]);
            }
        }

        try {
            $brief = $this->creative->proposeCreativeBrief(
                $project,
                $request->user(),
                $source,
                $validated['title'],
                $validated['payload'],
            );
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $this->audit->record(
            $request, $project, $request->user(),
            'propose_creative_brief', Domain::AUTHORITY_PROPOSE,
            ['source_concept_id' => $source?->id],
            ['brief_proposal_id' => $brief->id, 'status' => 'proposal', 'adopted' => false],
        );

        return response()->json([
            'project_id' => $project->id,
            'brief_proposal' => $this->briefPayload($brief),
            'adopted' => false,
        ], 201);
    }

    /* -------------------------------- helpers -------------------------------- */

    /** @return array<string, mixed> */
    private function conceptPayload(CreativeConcept $concept): array
    {
        return [
            'id' => $concept->id,
            'project_id' => $concept->project_id,
            'brainstorm_session_id' => $concept->brainstorm_session_id,
            'parent_concept_id' => $concept->parent_concept_id,
            'title' => $concept->title,
            'summary' => $concept->summary,
            'content' => $concept->content,
            'status' => $concept->status,
            'created_by' => $concept->created_by,
            'creator_name' => $concept->creator?->name,
            'lineage_basis' => $concept->lineage_basis,
            'adopted_at' => $concept->adopted_at?->toISOString(),
            'created_at' => $concept->created_at?->toISOString(),
            'items' => $concept->items->map(fn ($i) => [
                'id' => $i->id,
                'dimension' => $i->dimension,
                'label' => $i->label,
                'value' => $i->value,
                'source' => $i->source,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function briefPayload(CreativeBrief $brief): array
    {
        return [
            'id' => $brief->id,
            'status' => $brief->status,
            'creative_direction' => $brief->creative_direction,
            'tonality_notes' => $brief->tonality_notes,
            'deliverables' => $brief->deliverables,
            'payload' => $brief->payload,
        ];
    }
}
