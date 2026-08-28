<?php

namespace App\Domain\Retouch;

use App\Domain\Domain;

/**
 * Sprint 4 — a validated, normalized retouch adjustment set.
 *
 * Deterministic contract:
 *  - ONLY the six documented adjustment keys are accepted.
 *  - every value must be int|float inside -1.0 .. +1.0.
 *  - values are stored as floats; equivalent input always yields an identical
 *    normalized set (array order is canonicalized alphabetically).
 *  - unknown keys, non-numeric values, or out-of-range values throw
 *    InvalidAdjustmentException — server-side, before any rendering.
 *
 * These are NOT Lightroom units; each is a normalized offset interpreted
 * solely by the bound RetouchRenderer implementation.
 */
final class RetouchAdjustmentSet
{
    /** @var array<string, float> canonicalized adjustment => value */
    private readonly array $values;

    /**
     * @param  array<string, mixed>  $adjustments
     *
     * @throws InvalidAdjustmentException
     */
    public function __construct(array $adjustments)
    {
        if ($adjustments === []) {
            throw InvalidAdjustmentException::empty();
        }

        foreach ($adjustments as $key => $value) {
            if (! is_string($key) || ! in_array($key, Domain::RETOUCH_ADJUSTMENTS, true)) {
                throw InvalidAdjustmentException::unknownParameter(is_string($key) ? $key : '(non-string key)');
            }

            if (is_bool($value) || ! is_numeric($value)) {
                throw InvalidAdjustmentException::notNumeric($key);
            }

            $float = (float) $value;
            if (! is_finite($float) || $float < Domain::ADJUSTMENT_MIN || $float > Domain::ADJUSTMENT_MAX) {
                throw InvalidAdjustmentException::outOfRange($key, $float);
            }

            $values[$key] = $float;
        }

        ksort($values); // canonical order → identical input, identical persisted set

        $this->values = $values;
    }

    /** @return array<string, float> */
    public function toArray(): array
    {
        return $this->values;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key): ?float
    {
        return $this->values[$key] ?? null;
    }

    /** @return list<string> names of adjustments actually present */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    public function describe(): string
    {
        $parts = [];

        foreach ($this->values as $key => $value) {
            $direction = $value > 0.0 ? '+' : ($value < 0.0 ? '-' : '±');
            $parts[] = sprintf('%s %s%.2f', $key, $direction, abs($value));
        }

        return implode(', ', $parts);
    }
}
