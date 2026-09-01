<?php

namespace App\Http\Controllers\Webmcp;

use App\Domain\Domain;
use App\Domain\Retouch\RendererUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Proposal;
use App\Services\CreativeRoomService;
use App\Services\Culling\ContextAwareCullingService;
use App\Services\ProposalApplicator;
use App\Services\ProposalService;
use App\Services\Retouch\ContextAwareRetouchService;
use App\Services\ToolCallAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProposalController extends Controller
{
    public function __construct(
        private readonly ProposalService $proposals,
        private readonly ProposalApplicator $applicator,
        private readonly ToolCallAuditService $audit,
        private readonly ContextAwareCullingService $culling,
        private readonly ContextAwareRetouchService $retouch,
    ) {}

    /**
     * propose_cull — creates a cull proposal with items.
     * MUST NOT change actual photographer selections.
     *
     * Sprint 3: context-aware. Each item may be enriched from the photo's
     * CURRENT observation + the adopted structured Creative Brief via
     * ContextAwareCullingService, so proposal_items carry the full decision
     * evidence (recommendation, confidence, technical + creative fit,
     * rationale, influenced_by, similarity group). The photographer still
     * makes every final call — this endpoint only ever PROPOSES.
     */
    public function proposeCull(Request $request, Project $project): JsonResponse
    {
        return $this->createProposal($request, $project, Domain::TYPE_CULL);
    }

    /**
     * propose_retouch_plan — creates a proposal only. Does NOT apply edits.
     */
    public function proposeRetouchPlan(Request $request, Project $project): JsonResponse
    {
        return $this->createProposal($request, $project, Domain::TYPE_RETOUCH);
    }

    /**
     * Shared creation path for PROPOSE tools.
     */
    private function createProposal(Request $request, Project $project, string $type): JsonResponse
    {
        $this->authorize('propose', $project);
        $itemRules = $this->itemRulesFor($type);

        $validated = $request->validate([
            'summary' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.photo_id' => ['sometimes', 'integer', 'exists:photos,id'],
            'items.*.action' => ['required', 'string', 'max:64'],
            'items.*.rationale' => ['sometimes', 'string', 'max:2000'],
            'items.*.params' => ['sometimes', 'array'],
        ]);

        // Cross-check every referenced photo belongs to this project.
        $photoIds = collect($validated['items'])->pluck('photo_id')->filter();
        $projectPhotos = $project->photos()->whereIn('id', $photoIds)->pluck('id');
        $foreign = $photoIds->diff($projectPhotos);
        if ($foreign->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items.*.photo_id' => 'Photo(s) do not belong to this project: '.$foreign->implode(', '),
            ]);
        }

        try {
            $items = collect($validated['items'])->map(function ($item) use ($type, $project) {
                $merged = array_merge([
                    'kind' => $type === Domain::TYPE_CULL ? 'selection' : 'retouch_operation',
                ], $item);

                // Sprint 3 — context-aware enrichment for cull items: attach the
                // structured recommendation evidence to the item's params so the
                // photographer sees WHY, and the audit trail records WHAT moved it.
                if ($type === Domain::TYPE_CULL && isset($item['photo_id'])) {
                    $photo = $project->photos()->where('photos.id', $item['photo_id'])->first();
                    if ($photo !== null) {
                        $observation = $this->culling->observationFor($photo)
                            ?? $this->observeSingle($project, $photo);
                        if ($observation !== null) {
                            $direction = app(CreativeRoomService::class)
                                ->structuredIntentFor($project);
                            $recommendation = $this->culling->recommend(
                                $observation,
                                $direction['intent'] ?? null,
                            );
                            $merged['params'] = array_merge($merged['params'] ?? [], [
                                'context_aware' => true,
                                'recommendation' => $recommendation['recommendation'],
                                'confidence' => $recommendation['confidence'],
                                'technical_rationale' => $recommendation['technical_rationale'],
                                'creative_rationale' => $recommendation['creative_rationale'],
                                'tradeoff' => $recommendation['tradeoff'],
                                'influenced_by' => $recommendation['influenced_by'],
                                'similarity_group' => $observation->similarityGroup,
                                'observation_provenance' => [
                                    'technical' => $observation->provenance === Domain::OBSERVATION_PROVENANCE_DEMO_GD_UNAVAILABLE
                                        ? $observation->provenance
                                        : 'pixel_analysis',
                                    'creative' => 'demo_sidecar_annotation',
                                    'provider' => $observation->provider,
                                ],
                            ]);
                        }
                    }
                }

                // Sprint 4 — Creative-Brief-aware enrichment for retouch items:
                // derive the deterministic adjustment proposal from the photo's
                // observation + adopted brief and attach it as evidence so the
                // photographer sees WHY each adjustment was suggested. Still
                // PROPOSE-only: nothing renders until approval + execute.
                if (in_array($type, [Domain::TYPE_RETOUCH, Domain::TYPE_BATCH_RETOUCH], true) && isset($item['photo_id'])) {
                    $photo = $project->photos()->where('photos.id', $item['photo_id'])->first();
                    if ($photo !== null) {
                        $observation = $this->culling->observationFor($photo)
                            ?? $this->observeSingle($project, $photo);
                        if ($observation !== null) {
                            $retouchRec = $this->retouch->recommendForPhoto($project, $observation);
                            if ($retouchRec !== null) {
                                $merged['params'] = array_merge($merged['params'] ?? [], [
                                    'brief_aware' => $retouchRec['has_brief'],
                                    'derived_adjustments' => $retouchRec['adjustments'],
                                    'adjustments_summary' => $retouchRec['adjustments_summary'],
                                    'retouch_influenced_by' => $retouchRec['influenced_by'],
                                    'retouch_note' => $retouchRec['note'],
                                ]);
                            }
                        }
                    }
                }

                return $merged;
            })->all();

            $proposal = $this->proposals->createProposal(
                $project,
                $request->user(),
                $type,
                $items,
                $validated['summary'] ?? null,
                ['created_via' => 'webmcp', 'tool' => $type === Domain::TYPE_CULL ? 'propose_cull' : 'propose_retouch_plan'],
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $this->audit->record(
                $request,
                $project,
                $request->user(),
                $type === Domain::TYPE_CULL ? 'propose_cull' : 'propose_retouch_plan',
                Domain::AUTHORITY_PROPOSE,
                $validated,
                ['error' => 'proposal_creation_failed'],
                Domain::RESULT_ERROR,
            );

            return response()->json(['error' => 'Proposal creation failed.'], 422);
        }

        $this->audit->record(
            $request,
            $project,
            $request->user(),
            $type === Domain::TYPE_CULL ? 'propose_cull' : 'propose_retouch_plan',
            Domain::AUTHORITY_PROPOSE,
            $validated,
            ['proposal_id' => $proposal->id, 'type' => $proposal->type, 'status' => $proposal->status],
        );

        return response()->json($this->proposalPayload($proposal), 201);
    }

    /**
     * Analyze a single photo on demand (proposal path). Returns null when the
     * photo cannot be observed; the proposal still goes through without the
     * context-aware block rather than failing the whole propose.
     */
    private function observeSingle(Project $project, $photo)
    {
        $this->culling->analyzeProject($project);

        return $this->culling->observationFor($photo);
    }

    /**
     * apply_approved_plan — the ONLY execution tool.
     *
     * The browser only registers it while the project holds an eligible
     * approved proposal, but eligibility is re-verified here under a row
     * lock — the server is the authority, the browser is convenience.
     */
    public function execute(Request $request, Project $project, Proposal $proposal): JsonResponse
    {
        $this->assertCanExecute($request, $project, $proposal);
        $start = hrtime(true);

        try {
            $executed = $this->proposals->execute($proposal, $request->user(), function (Proposal $p) {
                // MUST return the summary: execute()'s honesty gate (0 items
                // applied → 422 + rollback) reads it. Dropping the return
                // made every all-failed execution look successful
                // (found live 2026-08-29 — proposal executed with a failed item).
                return $this->applicator->apply($p);
            });
        } catch (RendererUnavailableException $e) {
            $this->audit->record(
                $request,
                $project,
                $request->user(),
                'apply_approved_plan',
                Domain::AUTHORITY_EXECUTE,
                ['proposal_id' => $proposal->id],
                ['error' => $e->getMessage(), 'code' => 'renderer_unavailable'],
                Domain::RESULT_ERROR,
                (hrtime(true) - $start) / 1e6,
            );

            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'renderer_unavailable',
            ], 422);
        } catch (\RuntimeException $e) {
            // Honest execution failure: 0 items applied — proposal stays
            // approved and retryable. Audit + surface, never fake success.
            report($e);
            $this->logExecutionFailure($proposal, $e);

            $this->audit->record(
                $request,
                $project,
                $request->user(),
                'apply_approved_plan',
                Domain::AUTHORITY_EXECUTE,
                ['proposal_id' => $proposal->id],
                ['error' => $e->getMessage(), 'code' => 'execution_failed'],
                Domain::RESULT_ERROR,
                (hrtime(true) - $start) / 1e6,
            );

            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'execution_failed',
            ], 422);
        } catch (\LogicException $e) {
            $this->audit->record(
                $request,
                $project,
                $request->user(),
                'apply_approved_plan',
                Domain::AUTHORITY_EXECUTE,
                ['proposal_id' => $proposal->id],
                ['error' => $e->getMessage()],
                Domain::RESULT_DENIED,
            );

            return response()->json(['error' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            // Last-resort honesty net (found live 2026-08-29): an unexpected
            // error inside the applicator (e.g. a visibility Error from a
            // private helper) must NOT surface as a bare 500 "Server Error".
            // The transaction has already rolled back, so the proposal stays
            // approved — report it as a retryable execution failure.
            report($e);
            $this->logExecutionFailure($proposal, $e);

            $this->audit->record(
                $request,
                $project,
                $request->user(),
                'apply_approved_plan',
                Domain::AUTHORITY_EXECUTE,
                ['proposal_id' => $proposal->id],
                ['error' => 'execution_failed', 'code' => 'execution_failed', 'exception' => class_basename($e)],
                Domain::RESULT_ERROR,
                (hrtime(true) - $start) / 1e6,
            );

            return response()->json([
                'error' => 'Execution failed unexpectedly.',
                'code' => 'execution_failed',
            ], 422);
        }

        $this->audit->record(
            $request,
            $project,
            $request->user(),
            'apply_approved_plan',
            Domain::AUTHORITY_EXECUTE,
            ['proposal_id' => $proposal->id],
            ['status' => Domain::STATE_EXECUTED, 'items' => $executed->items->count()],
            Domain::RESULT_COMPLETED,
            (hrtime(true) - $start) / 1e6,
        );

        return response()->json($this->proposalPayload($executed));
    }

    /* ---------------------------------- helpers ---------------------------------- */

    /**
     * Emit after report() so a bounded root-cause record survives a truncated
     * Lambda stderr payload without exposing exception messages or response bodies.
     */
    private function logExecutionFailure(Proposal $proposal, \Throwable $exception): void
    {
        $cause = $exception->getPrevious();

        Log::error('webmcp_execute_failure', [
            'proposal_id' => $proposal->id,
            'exception' => $exception::class,
            'exception_code' => $exception->getCode(),
            'cause_exception' => $cause ? $cause::class : null,
            'cause_code' => $cause?->getCode(),
        ]);
    }

    private function assertCanExecute(Request $request, Project $project, Proposal $proposal): void
    {
        if ($proposal->project_id !== $project->id) {
            abort(404, 'Proposal does not belong to this project.');
        }

        $member = $project->members()->where('user_id', $request->user()->id)->first();
        if (! $member) {
            $this->audit->record(
                $request,
                $project,
                $request->user(),
                'apply_approved_plan',
                Domain::AUTHORITY_EXECUTE,
                ['proposal_id' => $proposal->id],
                ['error' => 'caller not a project member'],
                Domain::RESULT_DENIED,
            );
            abort(403, 'Not a member of this project.');
        }

        if ($member->pivot->role === Domain::ROLE_VIEWER) {
            abort(403, 'Viewer role cannot execute proposals.');
        }
    }

    private function itemRulesFor(string $type): array
    {
        // item-level params are validated per action in the applicator; here
        // we enforce the shape only (additionalProperties enforcement is a
        // browser-side JSON Schema concern for WebMCP).
        return [];
    }

    private function proposalPayload(Proposal $proposal): array
    {
        return [
            'proposal' => [
                'id' => $proposal->id,
                'project_id' => $proposal->project_id,
                'type' => $proposal->type,
                'status' => $proposal->status,
                'summary' => $proposal->summary,
                'created_by' => $proposal->created_by,
                'created_at' => $proposal->created_at?->toISOString(),
                'reviewed_at' => $proposal->reviewed_at?->toISOString(),
                'executed_at' => $proposal->executed_at?->toISOString(),
                'items' => $proposal->items->map(fn ($item) => [
                    'id' => $item->id,
                    'photo_id' => $item->photo_id,
                    'kind' => $item->kind,
                    'action' => $item->action,
                    'rationale' => $item->rationale,
                    'params' => $item->params,
                    'status' => $item->status,
                ])->values(),
            ],
            // Honest partial-execution accounting (Sol P1-6): the workspace
            // reads payload.execution to show applied/failed/skipped counts
            // instead of presenting a partial run as clean success.
            'payload' => [
                'execution' => $proposal->payload['execution'] ?? null,
            ],
        ];
    }
}
