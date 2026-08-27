<?php

namespace App\Services;

use App\Domain\Domain;
use App\Models\Photo;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\QaFinding;

/**
 * Applies an APPROVED proposal to the authoritative creative state.
 *
 * This is the only place in the codebase where the photos' selection or
 * retouch state is changed, and it is only ever reached through
 * ProposalService::execute() which enforces approval eligibility.
 *
 * Sprint 1 does NOT implement real image retouching. Retouch operations are
 * recorded as applied metadata (result snapshots) so the approval→execution
 * lifecycle is fully demonstrable and the swap-in boundary for a real
 * retouch engine (Sprint 2) is explicit.
 */
class ProposalApplicator
{
    /**
     * Apply the proposal and return a structured execution summary.
     *
     * @return array<string, mixed>
     */
    public function apply(Proposal $proposal): array
    {
        $results = [];

        foreach ($proposal->items as $item) {
            // Project-isolation guard: an item can only affect a photo in the
            // SAME project as the proposal. Foreign photos are skipped, never
            // written — even if some caller managed to smuggle one in.
            if ($item->photo_id !== null && $item->photo?->project_id !== $proposal->project_id) {
                $results[] = $this->skip($item, 'Photo does not belong to the proposal project — refused.');

                continue;
            }

            $results[] = match ($proposal->type) {
                Domain::TYPE_CULL => $this->applyCull($item),
                Domain::TYPE_RETOUCH,
                Domain::TYPE_BATCH_RETOUCH => $this->applyRetouchItem($proposal, $item),
                Domain::TYPE_QA_RESOLUTION => $this->applyQaResolution($item),
                default => $this->skip($item, "Unsupported proposal type [{$proposal->type}]."),
            };
        }

        return [
            'proposal' => $proposal->id,
            'type' => $proposal->type,
            'items_attempted' => $proposal->items->count(),
            'items_applied' => collect($results)->where('status', 'applied')->count(),
            'items_failed' => collect($results)->where('status', 'failed')->count(),
            'items_skipped' => collect($results)->where('status', 'skipped')->count(),
            'applied_at' => now()->toISOString(),
            'note' => 'Creative state updated from an approved photographer-approved plan. '
                .'Actual pixel editing engine lands in Sprint 2.',
            'items' => $results,
        ];
    }

    /* ---------------------------------- actions ---------------------------------- */

    private function applyCull(ProposalItem $item): array
    {
        $photo = $item->photo;
        if (! $photo) {
            return $this->fail($item, 'No photo attached to cull item.');
        }

        // NOTE: cull proposals may also include "select ... keep" positive picks.
        // The authoritative selection_state is what we change here.
        if ($item->action === 'cull' || $item->action === 'reject') {
            $photo->forceFill(['selection_state' => Domain::SELECTION_CULLED])->save();
        } elseif ($item->action === 'select' || $item->action === 'keep') {
            $photo->forceFill(['selection_state' => Domain::SELECTION_SELECTED])->save();
        } else {
            return $this->skip($item, "Unknown cull action [{$item->action}].");
        }

        $item->forceFill([
            'status' => 'applied',
            'result' => [
                'photo_id' => $photo->id,
                'selection_state' => $photo->selection_state,
                'applied_at' => now()->toISOString(),
            ],
        ])->save();

        return [
            'status' => 'applied',
            'action' => $item->action,
            'photo_id' => $photo->id,
            'selection_state' => $photo->selection_state,
        ];
    }

    private function applyRetouchItem(Proposal $proposal, ProposalItem $item): array
    {
        $photo = $item->photo;
        if (! $photo) {
            return $this->fail($item, 'No photo attached to retouch item.');
        }

        $photo->forceFill([
            'retouch_state' => Domain::RETOUCH_APPLIED,
        ])->save();

        $item->forceFill([
            'status' => 'applied',
            'result' => [
                'photo_id' => $photo->id,
                'operations' => $item->params ?? [],
                'retouch_state' => Domain::RETOUCH_APPLIED,
                // Placeholder for Sprint 2 real engine output.
                'rendered_preview' => null,
                'applied_at' => now()->toISOString(),
            ],
        ])->save();

        return [
            'status' => 'applied',
            'action' => $item->action,
            'photo_id' => $photo->id,
            'operations' => array_keys($item->params ?? []),
        ];
    }

    private function applyQaResolution(ProposalItem $item): array
    {
        $photo = $item->photo;
        $params = $item->params ?? [];

        // A qa_resolution item may reference findings directly.
        $findingIds = $params['finding_ids'] ?? [];
        foreach ($findingIds as $id) {
            $finding = QaFinding::where('id', $id)->where('project_id', $item->proposal->project_id)->first();
            if ($finding) {
                $finding->forceFill(['status' => 'resolved'])->save();
            }
        }

        if ($photo && $item->action === 'reprocess' || $item->action === 'apply_fix') {
            $photo->forceFill(['retouch_state' => Domain::RETOUCH_APPLIED])->save();
        }

        $item->forceFill([
            'status' => 'applied',
            'result' => ['resolved_finding_ids' => $findingIds, 'applied_at' => now()->toISOString()],
        ])->save();

        return ['status' => 'applied', 'action' => $item->action, 'photo_id' => $photo?->id, 'findings' => $findingIds];
    }

    /* ---------------------------------- helpers ---------------------------------- */

    private function skip(ProposalItem $item, string $reason): array
    {
        $item->forceFill(['status' => 'skipped', 'result' => ['reason' => $reason]])->save();

        return ['status' => 'skipped', 'action' => $item->action, 'reason' => $reason];
    }

    private function fail(ProposalItem $item, string $reason): array
    {
        $item->forceFill(['status' => 'failed', 'result' => ['error' => $reason]])->save();

        return ['status' => 'failed', 'action' => $item->action, 'reason' => $reason];
    }
}
