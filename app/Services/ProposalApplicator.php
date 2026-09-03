<?php

namespace App\Services;

use App\Domain\Domain;
use App\Domain\Retouch\InvalidAdjustmentException;
use App\Domain\Retouch\RendererUnavailableException;
use App\Domain\Retouch\RetouchAdjustmentSet;
use App\Models\Photo;
use App\Models\PhotoDerivative;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\QaFinding;
use App\Services\Media\MediaStore;
use App\Services\Retouch\RetouchRenderer;
use RuntimeException;
use Throwable;

/**
 * Applies an APPROVED proposal to the authoritative creative state.
 *
 * This is the only place in the codebase where the photos' selection or
 * retouch state is changed, and it is only ever reached through
 * ProposalService::execute() which enforces approval eligibility.
 *
 * Sprint 4: retouch execution now renders a NON-DESTRUCTIVE derivative JPEG
 * through the bound RetouchRenderer. The original file is NEVER overwritten:
 * bytes are hashed before and after execution, and the derivative is stored
 * under its own path in photo_derivatives.
 */
class ProposalApplicator
{
    public function __construct(
        private readonly RetouchRenderer $renderer,
    ) {}

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

        // Sprint 4 — render a NON-DESTRUCTIVE derivative. The original is
        // hashed before and after; any renderer failure leaves it untouched.
        $originalHashBefore = $this->originalHash($photo);

        if ($originalHashBefore === null) {
            return $this->fail($item, "Original file for photo [{$photo->id}] is missing — refusing to render a derivative from nothing.");
        }

        // Sprint 4 — proposal items carry BOTH executable adjustment values
        // and read-only brief-awareness evidence (brief_aware,
        // derived_adjustments, adjustments_summary, retouch_influenced_by,
        // retouch_note). Only the six documented adjustment keys are
        // executable; the evidence block is provenance metadata and must
        // never reach the renderer's validation.
        $executable = array_intersect_key(
            (array) ($item->params ?? []),
            array_fill_keys(Domain::RETOUCH_ADJUSTMENTS, true),
        );

        try {
            $adjustments = new RetouchAdjustmentSet($executable);
        } catch (InvalidAdjustmentException $e) {
            return $this->fail($item, 'Adjustment set rejected: '.$e->getMessage());
        }

        $derivative = $this->renderDerivative($proposal, $photo, $adjustments);

        if ($derivative === null) {
            return $this->fail($item, "Renderer failure while producing derivative for photo [{$photo->id}] — original untouched.");
        }

        // Byte-for-byte original immutability check after execution.
        if ($this->originalHash($photo) !== $originalHashBefore) {
            // This should be impossible (the renderer never writes to the
            // original path); treat it as a hard failure and report honestly.
            return $this->fail($item, "INTEGRITY VIOLATION: original bytes changed during derivative render for photo [{$photo->id}].");
        }

        $photo->forceFill([
            'retouch_state' => Domain::RETOUCH_APPLIED,
        ])->save();

        $item->forceFill([
            'status' => 'applied',
            'result' => [
                'photo_id' => $photo->id,
                'operations' => $adjustments->toArray(),
                'adjustments_summary' => $adjustments->describe(),
                'retouch_state' => Domain::RETOUCH_APPLIED,
                'derivative_id' => $derivative->id,
                'derivative_path' => $derivative->storage_path,
                'derivative_type' => $derivative->type,
                'renderer_provenance' => $derivative->provenance,
                'original_sha256_before' => $originalHashBefore,
                'original_untouched' => true,
                'applied_at' => now()->toISOString(),
            ],
        ])->save();

        return [
            'status' => 'applied',
            'action' => $item->action,
            'photo_id' => $photo->id,
            'operations' => $adjustments->keys(),
            'derivative_id' => $derivative->id,
        ];
    }

    /**
     * Render and persist (idempotently) the approved_render derivative for a
     * photo. Repeated execution never duplicates: one row per (photo, type).
     * A renderer failure NEVER corrupts the original — the derivative write
     * happens only after a successful in-memory render.
     */
    private function renderDerivative(Proposal $proposal, Photo $photo, RetouchAdjustmentSet $adjustments): ?PhotoDerivative
    {
        // Idempotency: an existing approved_render for this photo is reused
        // and, when the adjustment set matches, left byte-for-byte untouched.
        $existing = PhotoDerivative::where('photo_id', $photo->id)
            ->where('type', Domain::DERIVATIVE_APPROVED_RENDER)
            ->first();

        try {
            $rendered = $this->renderer->render($photo, $adjustments);
        } catch (RendererUnavailableException $e) {
            throw $e;
        } catch (InvalidAdjustmentException|RuntimeException $e) {
            return null;
        }

        // Persist through MediaStore so durable deployments store the
        // derivative bytes in Vercel Blob and the row keeps the public URL
        // (never a lambda-local /tmp path — Sol Max P0).
        $media = app(MediaStore::class);
        $relativePath = $this->derivativePath($photo);
        $dir = trim(dirname($relativePath), '.');
        $filename = pathinfo($relativePath, PATHINFO_BASENAME);

        // Idempotency: the row's storage_path is store-relative for local
        // records and an absolute Blob URL for durable records. Match either
        // shape against the resolved relative target — comparing only the
        // raw pathname never matches a durable URL, which silently disabled
        // the idempotent skip for durable photos.
        if ($existing
            && ($existing->storage_path === $relativePath
                || ($media->isHttpPath((string) $existing->storage_path)
                    && str_ends_with((string) parse_url((string) $existing->storage_path, PHP_URL_PATH), '/'.$relativePath)))
            && $media->exists($existing->storage_path)) {
            // Already-rendered with the same target path: overwrite with the
            // fresh (identical) bytes only if adjustments differ; otherwise
            // keep the existing file untouched.
            if ($existing->adjustments !== $adjustments->toArray()) {
                $stored = $media->writeBytes(
                    $dir,
                    $rendered['jpeg'],
                    $filename,
                    'image/jpeg',
                    allowOverwrite: true,
                );
                try {
                    $existing->forceFill([
                        'storage_path' => $media->recordPath($stored),
                        'adjustments' => $adjustments->toArray(),
                        'proposal_id' => $proposal->id,
                        'prior_photo_state' => $existing->prior_photo_state ?? $photo->retouch_state,
                        'reverted_at' => null, // a re-execution un-reverts the row
                    ])->save();
                } catch (Throwable $e) {
                    // Compensating delete (AGY M-2): the update path writes new
                    // bytes before the row save — a failed save must not leave
                    // the just-written object orphaned in billable storage.
                    $media->delete($media->recordPath($stored));

                    throw $e;
                }
            }

            return $existing;
        }

        $stored = $media->writeBytes(
            $dir,
            $rendered['jpeg'],
            $filename,
            'image/jpeg',
            allowOverwrite: true,
        );
        $storagePath = $media->recordPath($stored);

        if ($existing) {
            // Same photo re-rendered but path changed (rare) — move the row.
            try {
                $existing->forceFill([
                    'storage_path' => $storagePath,
                    'adjustments' => $adjustments->toArray(),
                    'proposal_id' => $proposal->id,
                    'prior_photo_state' => $existing->prior_photo_state ?? $photo->retouch_state,
                    'reverted_at' => null, // a re-execution un-reverts the row
                ])->save();
            } catch (Throwable $e) {
                // Compensating delete (AGY M-2): unlike the same-path overwrite,
                // the OLD bytes stay valid at the old path, but the NEW object
                // at the new path is unreferenced after a failed row move.
                $media->delete($storagePath);

                throw $e;
            }

            return $existing;
        }

        try {
            return PhotoDerivative::create([
                'project_id' => $proposal->project_id,
                'photo_id' => $photo->id,
                'proposal_id' => $proposal->id,
                'type' => Domain::DERIVATIVE_APPROVED_RENDER,
                'storage_path' => $storagePath,
                'adjustments' => $adjustments->toArray(),
                'provenance' => $rendered['provenance'],
                'created_by' => $proposal->created_by,
                // B3: archive the pre-execution retouch marker so a
                // photographer revert restores exactly this state.
                'prior_photo_state' => $photo->retouch_state,
            ]);
        } catch (Throwable $e) {
            // Compensating delete: never orphan stored derivative bytes
            // behind a failed row insert. recordPath() yields the canonical
            // handle (absolute Blob URL in durable mode); passing the raw
            // relative path would silently skip the remote delete and leave
            // orphaned billable bytes (Sol P1-4).
            $media->delete($media->recordPath($stored));

            throw $e;
        }
    }

    /**
     * Deterministic derivative storage pathname — distinct from the original,
     * and always STORE-RELATIVE (never an absolute URL). For durable photos
     * the path column holds an absolute Blob URL; taking dirname() of that
     * produced a double-prefixed pathname
     * (https://…store/https%3A//…store/project-1/x.retouched.jpg — found in
     * the 2026-08-29 production E2E). Parse the URL path instead so the
     * derivative lands beside the original at a clean relative pathname;
     * MediaStore::recordPath converts it back to an absolute durable URL.
     */
    private function derivativePath(Photo $photo): string
    {
        $path = (string) $photo->path;

        if (str_starts_with($path, 'http')) {
            $queryPos = strpos($path, '?');
            $urlPath = $queryPos === false ? $path : substr($path, 0, $queryPos);
            $segments = explode('/', ltrim((string) parse_url($urlPath, PHP_URL_PATH), '/'));

            $name = (string) pathinfo((string) array_pop($segments), PATHINFO_FILENAME);
            $dir = implode('/', $segments);

            return ($dir === '' ? '' : $dir.'/').$name.'.retouched.jpg';
        }

        $dir = dirname($path);
        $name = pathinfo($path, PATHINFO_FILENAME);

        return ($dir === '.' ? '' : $dir.'/').$name.'.retouched.jpg';
    }

    /** sha256 of the original file, or null when it cannot be read. */
    private function originalHash(Photo $photo): ?string
    {
        if (! $photo->path) {
            return null;
        }

        try {
            $bytes = app(MediaStore::class)->read($photo->path);
        } catch (Throwable) {
            return null;
        }

        return $bytes === '' ? null : hash('sha256', $bytes);
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

        if ($photo && in_array($item->action, ['reprocess', 'apply_fix'], true)) {
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
