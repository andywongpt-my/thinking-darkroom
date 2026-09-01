<?php

namespace App\Services\Retouch;

use App\Domain\Retouch\InvalidAdjustmentException;
use App\Domain\Retouch\RetouchAdjustmentSet;
use App\Models\Photo;

/**
 * Sprint 4 — the retouch render abstraction.
 *
 * Contract (enforced by DemoRetouchRendererTest and the applicator):
 *  - input: the ORIGINAL photo + an already-validated adjustment set
 *  - output: byte content of a NEW derivative JPEG — never the original path
 *  - deterministic: identical inputs → identical output bytes
 *  - failure-safe: a renderer failure can never corrupt or delete the original
 *  - provenance: honest self-identification persisted with every derivative
 */
interface RetouchRenderer
{
    /**
     * Render a derivative JPEG of the original photo with the given
     * adjustments. Implementations MUST read the original read-only and
     * return the derivative bytes; persistence is the caller's job.
     *
     * @return array{jpeg: string, provenance: string}
     *
     * @throws InvalidAdjustmentException when the set is invalid
     * @throws \RuntimeException when rendering is impossible (no pixels, corrupt input)
     */
    public function render(Photo $photo, RetouchAdjustmentSet $adjustments): array;

    /**
     * Stable renderer identifier persisted as derivative provenance.
     */
    public function provenance(): string;
}
