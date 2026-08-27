<?php

namespace App\Services\Culling;

use App\Domain\Culling\PhotoObservation;
use App\Models\Photo;

/**
 * Contract for producing structured photo observations.
 *
 * Sprint 3 hackathon rule: product truth over pretending. The demo provider
 * below is a DETERMINISTIC on-device pixel-statistics analyzer (GD) — no
 * external AI, no ML claims. If a real VLM provider is added later it must:
 *  - be isolated behind this interface,
 *  - have its structured output validated server-side,
 *  - never bypass server-side domain authority (observations are data only).
 *
 * Every implementation must honestly declare provider + provenance.
 */
interface PhotoAnalysisProvider
{
    /** Machine name recorded on every observation row (Domain::OBSERVATION_PROVIDER_*). */
    public function name(): string;

    /** Provenance string (Domain::OBSERVATION_PROVENANCES keys). */
    public function provenance(): string;

    /** Analyze one photo into structured observations. */
    public function observe(Photo $photo): PhotoObservation;
}
