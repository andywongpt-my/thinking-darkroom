<?php

namespace App\Support;

/**
 * Reports whether the GD functions required by the image analysis and retouch
 * pipelines are available in the current PHP runtime.
 *
 * The optional override is a test seam. Production callers leave it null so
 * the runtime capability is detected directly.
 */
final class GdAvailability
{
    /** @var list<string> */
    private const REQUIRED_FUNCTIONS = [
        'imagecreatefromstring',
        'imagecreatetruecolor',
        'imagecopyresampled',
        'imagecopy',
        'imagesx',
        'imagesy',
        'imagecolorat',
        'imagesetpixel',
        'imagejpeg',
        'imagedestroy',
    ];

    public function __construct(private readonly ?bool $availabilityOverride = null)
    {
    }

    public function isAvailable(): bool
    {
        if ($this->availabilityOverride !== null) {
            return $this->availabilityOverride;
        }

        foreach (self::REQUIRED_FUNCTIONS as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }

        return true;
    }
}
