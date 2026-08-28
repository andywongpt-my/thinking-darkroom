<?php

namespace App\Domain\Retouch;

use RuntimeException;

/**
 * Raised when the configured retouch renderer cannot run in this PHP runtime.
 */
final class RendererUnavailableException extends RuntimeException
{
    public static function gdUnavailable(): self
    {
        return new self(
            'Retouch rendering is unavailable on this deployment because the GD image extension is not available. '
            .'The approved plan was not applied; retry on a GD-enabled deployment.',
        );
    }
}
