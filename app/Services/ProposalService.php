<?php

namespace App\Services;

use App\Domain\Domain;
use App\Models\Photo;
use App\Models\PhotographerDecision;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Owns every state transition on proposals and the creative-authority rules
 * around them.
 *
 * Photographer authority is enforced here and in the controller layer:
 *  - The agent / WebMCP layer can only ever create proposals (PROPOSE).
 *  - Approve / reject / modify are invoked BY a photographer, never by an
 *    agent tool.
 *  - Execution (EXECUTE) is only possible for an approved, unexecuted
 *    proposal, and is exposed to the agent as a dynamically-registered tool.
 */
class ProposalService
{
    /* ------------------------------ proposal creation ------------------------------ */

    /**
     * Create a proposal with its items. Strictly PROPOSE — it never touches
     * the photos' authoritative selection/retouch state.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $payload
     */
    public function createProposal(
        Project $project,
        User $creator,
        string $type,
        array $items,
        ?string $summary = null,
        ?array $payload = null,
        string $status = Domain::STATE_PENDING_REVIEW,
    ): Proposal {
        return DB::transaction(function () use ($project, $creator, $type, $items, $summary, $payload, $status) {
            $proposal = Proposal::create([
                'project_id' => $project->id,
                'created_by' => $creator->id,
                'type' => $type,
                'status' => $status,
                'summary' => $summary,
                'payload' => $payload,
            ]);

            foreach ($items as $item) {
                /** @var Photo|null $photo */
                $photo = isset($item['photo_id']) ? Photo::find($item['photo_id']) : null;

                ProposalItem::create([
                    'proposal_id' => $proposal->id,
                    'photo_id' => $photo?->id,
                    'kind' => $item['kind'] ?? 'selection',
                    'action' => $item['action'] ?? 'select',
                    'rationale' => $item['rationale'] ?? null,
                    'params' => $item['params'] ?? [],
                    'status' => 'proposed',
                ]);

                // Mark the retouch lifecycle as "proposed" for tracking only.
                // The authoritative creative state remains untouched.
                if ($photo !== null && in_array($type, [
                    Domain::TYPE_RETOUCH,
                    Domain::TYPE_BATCH_RETOUCH,
                    Domain::TYPE_QA_RESOLUTION,
                ], true)) {
                    if ($photo->retouch_state === Domain::RETOUCH_NONE) {
                        $photo->forceFill(['retouch_state' => Domain::RETOUCH_PROPOSED])->saveQuietly();
                    }
                }
            }

            return $proposal->load('items.photo');
        });
    }

    /* --------------------------------- decisions --------------------------------- */

    /**
     * Photographer approves a proposal. Only a human may call this.
     */
    public function approve(Proposal $proposal, User $photographer, ?string $note = null): Proposal
    {
        return DB::transaction(function () use ($proposal, $photographer, $note) {
            // Reviewability is checked on the LOCKED row (Sol P1-5): two
            // photographers submitting approve+reject concurrently must not
            // both pass the pre-transaction check and produce contradictory
            // terminal decisions.
            $locked = $this->lockReviewable($proposal->id);

            $locked->forceFill([
                'status' => Domain::STATE_APPROVED,
                'reviewed_by' => $photographer->id,
                'reviewed_at' => now(),
            ])->save();

            PhotographerDecision::create([
                'project_id' => $locked->project_id,
                'proposal_id' => $locked->id,
                'photographer_id' => $photographer->id,
                'decision' => 'approve',
                'note' => $note,
            ]);

            // Only an approved proposal may carry the approved retouch marker.
            if (in_array($locked->type, [Domain::TYPE_RETOUCH, Domain::TYPE_BATCH_RETOUCH], true)) {
                $locked->items()
                    ->whereNotNull('photo_id')
                    ->get()
                    ->each(function (ProposalItem $item) {
                        $item->photo?->forceFill(['retouch_state' => Domain::RETOUCH_APPROVED])->saveQuietly();
                    });
            }

            return $locked->fresh(['items.photo']);
        });
    }

    /**
     * Photographer cancels an approval before execution, returning the
     * proposal to the review queue.
     */
    public function cancelApproval(Proposal $proposal, User $photographer, ?string $note = null): Proposal
    {
        return DB::transaction(function () use ($proposal, $photographer, $note) {
            $locked = $this->lockApprovedUnexecuted($proposal->id);

            $locked->forceFill([
                'status' => Domain::STATE_PENDING_REVIEW,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();

            PhotographerDecision::create([
                'project_id' => $locked->project_id,
                'proposal_id' => $locked->id,
                'photographer_id' => $photographer->id,
                'decision' => 'cancel',
                'note' => $note,
            ]);

            // A cancelled retouch approval is only proposed again; it has not
            // been approved or executed.
            if (in_array($locked->type, [Domain::TYPE_RETOUCH, Domain::TYPE_BATCH_RETOUCH], true)) {
                $locked->items()
                    ->whereNotNull('photo_id')
                    ->get()
                    ->each(function (ProposalItem $item) {
                        $item->photo?->forceFill(['retouch_state' => Domain::RETOUCH_PROPOSED])->saveQuietly();
                    });
            }

            return $locked->fresh(['items.photo']);
        });
    }

    /**
     * Photographer rejects a proposal.
     */
    public function reject(Proposal $proposal, User $photographer, ?string $note = null): Proposal
    {
        return DB::transaction(function () use ($proposal, $photographer, $note) {
            $locked = $this->lockReviewable($proposal->id);

            $locked->forceFill([
                'status' => Domain::STATE_REJECTED,
                'reviewed_by' => $photographer->id,
                'reviewed_at' => now(),
            ])->save();

            PhotographerDecision::create([
                'project_id' => $locked->project_id,
                'proposal_id' => $locked->id,
                'photographer_id' => $photographer->id,
                'decision' => 'reject',
                'note' => $note,
            ]);

            $this->resetRetouchMarkers($locked);

            return $locked->fresh(['items.photo']);
        });
    }

    /**
     * Photographer asks for a modified plan. The original proposal is marked
     * `modified` and a new superseding proposal is created in draft form for
     * the agent to refine.
     *
     * @param  array<string, mixed>|null  $modifications
     */
    public function requestModification(
        Proposal $proposal,
        User $photographer,
        ?string $note = null,
        ?array $modifications = null,
    ): Proposal {
        return DB::transaction(function () use ($proposal, $photographer, $note, $modifications) {
            $locked = $this->lockReviewable($proposal->id);

            $locked->forceFill([
                'status' => Domain::STATE_MODIFIED,
                'reviewed_by' => $photographer->id,
                'reviewed_at' => now(),
            ])->save();

            PhotographerDecision::create([
                'project_id' => $locked->project_id,
                'proposal_id' => $locked->id,
                'photographer_id' => $photographer->id,
                'decision' => 'modify',
                'note' => $note,
                'modifications' => $modifications,
            ]);

            $this->resetRetouchMarkers($locked);

            // Sprint 4 — for retouch proposals the photographer may supply
            // edited adjustment VALUES (`modifications.adjustments`). When
            // given, the superseding proposal is created directly in
            // PENDING_REVIEW with the photographer's values as executable
            // params; the original agent values are preserved in payload.
            $isRetouch = in_array($proposal->type, [Domain::TYPE_RETOUCH, Domain::TYPE_BATCH_RETOUCH], true);
            $adjustments = $modifications['adjustments'] ?? ($modifications['params'] ?? null);
            $photographerEdited = $isRetouch && is_array($adjustments) && $adjustments !== [];

            $superseding = Proposal::create([
                'project_id' => $proposal->project_id,
                'created_by' => $proposal->created_by,
                'type' => $proposal->type,
                'status' => $photographerEdited ? Domain::STATE_PENDING_REVIEW : Domain::STATE_DRAFT,
                'summary' => ($note ? $note.' — ' : 'Revised plan based on photographer feedback. ')
                    .($modifications['summary'] ?? ''),
                'payload' => [
                    'refines' => $proposal->id,
                    'feedback' => $modifications,
                    // Preserve the ORIGINAL agent values for honest history.
                    'original_items' => $proposal->items->map(fn ($i) => [
                        'photo_id' => $i->photo_id,
                        'params' => $i->params,
                        'rationale' => $i->rationale,
                    ])->all(),
                ],
                'supersedes_id' => $proposal->id,
            ]);

            if ($photographerEdited) {
                foreach ($proposal->items as $item) {
                    ProposalItem::create([
                        'proposal_id' => $superseding->id,
                        'photo_id' => $item->photo_id,
                        'kind' => $item->kind,
                        'action' => $item->action,
                        'rationale' => $item->rationale,
                        'params' => $adjustments,
                        'status' => 'proposed',
                    ]);
                }

                $superseding->items()
                    ->whereNotNull('photo_id')
                    ->get()
                    ->each(function (ProposalItem $item) {
                        $item->photo?->forceFill(['retouch_state' => Domain::RETOUCH_PROPOSED])->saveQuietly();
                    });
            }

            return $superseding;
        });
    }

    /* --------------------------------- execution --------------------------------- */

    /**
     * Execute an approved proposal. Only the WebMCP `apply_approved_plan`
     * tool path may reach here, and it is gated by `isEligibleForExecution`.
     *
     * @param  callable(Proposal): void  $applicator
     */
    public function execute(Proposal $proposal, User $actor, callable $applicator): Proposal
    {
        // Eligibility is judged on a FRESH read, not the caller's possibly
        // stale in-memory model: approve()/reject() mutate the locked row via
        // a separate instance (Sol P1-5 CAS), so a proposal approved moments
        // ago still reads `pending_review` in the caller's copy. The
        // authoritative gate remains the re-lock below inside the transaction.
        $fresh = Proposal::find($proposal->id);
        if ($fresh === null || ! $fresh->isEligibleForExecution()) {
            throw new \LogicException('Proposal is not eligible for execution.');
        }

        return DB::transaction(function () use ($proposal, $actor, $applicator) {
            // Re-lock the row so two concurrent executions cannot both pass.
            $locked = Proposal::whereKey($proposal->id)->lockForUpdate()->first();
            if (! $locked->isEligibleForExecution()) {
                throw new \LogicException('Proposal has already been executed or invalidated.');
            }

            $summary = $applicator($locked);

            $attempted = (int) ($summary['items_attempted'] ?? 0);
            $applied = (int) ($summary['items_applied'] ?? 0);
            $failed = (int) ($summary['items_failed'] ?? 0);
            $skipped = (int) ($summary['items_skipped'] ?? 0);

            // Honesty gate (Sol Max P1): if NOTHING applied, do not mark the
            // proposal executed. The transaction rolls back — the proposal
            // stays approved so the photographer can retry after the fix.
            if ($attempted > 0 && $applied === 0) {
                $firstFailure = collect($summary['items'] ?? [])
                    ->first(fn ($i) => ($i['status'] ?? '') !== 'applied')['reason']
                    ?? 'unknown reason';

                throw new \RuntimeException(
                    "Execution failed: 0 of {$attempted} items applied — proposal remains approved and retryable. First failure: {$firstFailure}",
                );
            }

            if ($failed > 0 || $skipped > 0) {
                // Partial success: still executed, but the full summary is
                // persisted in the payload so the UI/API can show honestly.
                // (In-memory fill; the status save() below persists it.)
                $locked->forceFill([
                    'payload' => array_merge((array) ($locked->payload ?? []), ['execution' => $summary]),
                ]);
            }

            $locked->forceFill([
                'status' => Domain::STATE_EXECUTED,
                'executed_at' => now(),
                'reviewed_by' => $locked->reviewed_by ?? $actor->id,
            ])->save();

            return $locked->fresh(['items.photo']);
        });
    }

    /* ---------------------------------- helpers ---------------------------------- */

    /**
     * Lock the proposal row and re-verify reviewability INSIDE the caller's
     * transaction (Sol P1-5). The pre-transaction check alone let two
     * concurrent reviewers both observe `pending_review` and write
     * contradictory terminal decisions (approved + rejected on one proposal).
     *
     * @return Proposal the locked, still-reviewable proposal
     */
    private function lockReviewable(int $proposalId): Proposal
    {
        $locked = Proposal::whereKey($proposalId)->lockForUpdate()->first();

        if ($locked === null) {
            throw new \LogicException('Proposal not found.');
        }

        if (! in_array($locked->status, [Domain::STATE_PENDING_REVIEW, Domain::STATE_DRAFT], true)) {
            throw new \LogicException("Cannot review a proposal in state [{$locked->status}].");
        }

        return $locked;
    }

    private function lockApprovedUnexecuted(int $proposalId): Proposal
    {
        $locked = Proposal::whereKey($proposalId)->lockForUpdate()->first();

        if ($locked === null) {
            throw new \LogicException('Proposal not found.');
        }

        if ($locked->status !== Domain::STATE_APPROVED || $locked->executed_at !== null) {
            $state = $locked->executed_at !== null ? Domain::STATE_EXECUTED : $locked->status;
            throw new \LogicException("Cannot cancel approval for a proposal in state [{$state}].");
        }

        return $locked;
    }

    private function resetRetouchMarkers(Proposal $proposal): void
    {
        $proposal->items()
            ->whereNotNull('photo_id')
            ->get()
            ->each(function (ProposalItem $item) use ($proposal) {
                $photo = $item->photo;
                if (! $photo) {
                    return;
                }
                // Only roll back the marker if no OTHER pending proposal targets it.
                $otherPending = Proposal::where('project_id', $proposal->project_id)
                    ->where('id', '!=', $proposal->id)
                    ->whereIn('status', [Domain::STATE_DRAFT, Domain::STATE_PENDING_REVIEW, Domain::STATE_APPROVED])
                    ->whereHas('items', fn ($q) => $q->where('photo_id', $photo->id))
                    ->exists();

                if (! $otherPending) {
                    $photo->forceFill(['retouch_state' => Domain::RETOUCH_NONE])->saveQuietly();
                }
            });
    }
}
