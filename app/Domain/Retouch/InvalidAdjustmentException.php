<?php

namespace App\Domain\Retouch;

use RuntimeException;

/**
 * Deterministic rejection of an invalid retouch adjustment set.
 * Unknown/unsupported or out-of-range parameters MUST fail before rendering.
 */
final class InvalidAdjustmentException extends RuntimeException
{
    public static function unknownParameter(string $key): self
    {
        return new self("Unknown retouch adjustment parameter [{$key}].");
    }

    public static function notNumeric(string $key): self
    {
        return new self("Retouch adjustment [{$key}] must be a number.");
    }

    public static function outOfRange(string $key, float $value): self
    {
        return new self(sprintf(
            'Retouch adjustment [%s] value %s is outside the normalized range %.1f..%.1f.',
            $key,
            $value,
            \App\Domain\Domain::ADJUSTMENT_MIN,
            \App\Domain\Domain::ADJUSTMENT_MAX,
        ));
    }

    public static function empty(): self
    {
        return new self('Retouch adjustment set must contain at least one adjustment.');
    }
}
