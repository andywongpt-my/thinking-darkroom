<?php

namespace App\Services\Retouch;

use App\Domain\Domain;
use App\Domain\Retouch\InvalidAdjustmentException;
use App\Domain\Retouch\RetouchAdjustmentSet;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Sprint 4 — deterministic GD-based demo renderer.
 *
 * A small, explainable, NON-DESTRUCTIVE JPEG preview pipeline. Global,
 * normalized adjustments only — this is deliberately NOT a Lightroom or
 * Photoshop replacement (no masks, no segmentation, no generative tools).
 *
 * Determinism strategy:
 *  - the source JPEG is decoded once and re-encoded at fixed quality 92 with
 *    no EXIF pass-through, so identical input pixels + identical adjustments
 *    produce identical output bytes (JPEG encoding is deterministic in GD).
 *  - all math is per-channel integer arithmetic on RGB — no randomness, no
 *    time, no environment-dependent behavior.
 *
 * Failure safety: the original is opened read-only from the public disk and
 * never written; all work happens on an in-memory GD image.
 */
class DemoRetouchRenderer implements RetouchRenderer
{
    public const JPEG_QUALITY = 92;

    /** clamps a byte value to 0..255 */
    private static function clamp(int $value): int
    {
        return max(0, min(255, $value));
    }

    public function provenance(): string
    {
        return Domain::RENDERER_PROVENANCE_DEMO;
    }

    public function render(Photo $photo, RetouchAdjustmentSet $adjustments): array
    {
        $image = $this->loadOriginal($photo);

        try {
            foreach ($adjustments->toArray() as $adjustment => $value) {
                $image = match ($adjustment) {
                    'exposure' => self::applyExposure($image, $value),
                    'contrast' => self::applyContrast($image, $value),
                    'saturation' => self::applySaturation($image, $value),
                    'warmth' => self::applyWarmth($image, $value),
                    'highlight_recovery' => self::applyHighlightRecovery($image, $value),
                    'shadow_lift' => self::applyShadowLift($image, $value),
                    default => throw InvalidAdjustmentException::unknownParameter($adjustment),
                };
            }

            ob_start();
            try {
                if (! imagejpeg($image, null, self::JPEG_QUALITY)) {
                    throw new RuntimeException('GD failed to encode the derivative JPEG.');
                }
                $jpeg = (string) ob_get_clean();
            } catch (\Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }

                throw $e;
            }
        } finally {
            if (is_resource($image) || $image instanceof \GdImage) {
                imagedestroy($image);
            }
        }

        return ['jpeg' => $jpeg, 'provenance' => $this->provenance()];
    }

    /**
     * Read-only load of the original. NEVER writes to the original path.
     *
     * @return resource|\GdImage
     */
    private function loadOriginal(Photo $photo)
    {
        if (! $photo->path) {
            throw new RuntimeException("Photo [{$photo->id}] has no stored file to render from.");
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($photo->path)) {
            throw new RuntimeException("Original file for photo [{$photo->id}] is missing from storage.");
        }

        $bytes = $disk->get($photo->path);
        $image = $bytes === false || $bytes === '' ? null : @imagecreatefromstring($bytes);

        if ($image === false || $image === null) {
            throw new RuntimeException("Original file for photo [{$photo->id}] could not be decoded as an image.");
        }

        return $image;
    }

    /* ------------------------------ adjustments ------------------------------ */

    /**
     * Exposure: uniform multiplicative lift on all channels.
     * -1.0 → half brightness, +1.0 → double brightness.
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private static function applyExposure($image, float $value)
    {
        $factor = 1.0 + $value; // -1..+1 → 0..2
        $lookup = [];
        for ($i = 0; $i <= 255; $i++) {
            $lookup[$i] = self::clamp((int) round($i * $factor));
        }

        return self::applyLut($image, $lookup, $lookup, $lookup);
    }

    /**
     * Contrast: pivot around mid-gray (127.5). Positive expands, negative
     * compresses toward mid-gray.
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private static function applyContrast($image, float $value)
    {
        $factor = 1.0 + $value; // 0..2
        $pivot = 127.5;
        $r = $g = $b = [];
        for ($i = 0; $i <= 255; $i++) {
            $v = self::clamp((int) round($pivot + ($i - $pivot) * $factor));
            $r[$i] = $g[$i] = $b[$i] = $v;
        }

        return self::applyLut($image, $r, $g, $b);
    }

    /**
     * Saturation: scale each channel's distance from the pixel's luminance.
     * -1.0 → grayscale, +1.0 → double separation.
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private static function applySaturation($image, float $value)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $out = imagecreatetruecolor($width, $height);
        imagecopy($out, $image, 0, 0, 0, 0, $width, $height);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $luma = 0.299 * $r + 0.587 * $g + 0.114 * $b;

                $nr = self::clamp((int) round($luma + ($r - $luma) * (1.0 + $value)));
                $ng = self::clamp((int) round($luma + ($g - $luma) * (1.0 + $value)));
                $nb = self::clamp((int) round($luma + ($b - $luma) * (1.0 + $value)));

                $color = ($nr << 16) | ($ng << 8) | $nb;
                imagesetpixel($out, $x, $y, $color);
            }
        }

        return $out;
    }

    /**
     * Warmth: additive shift — red up, blue down (positive = warmer).
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private static function applyWarmth($image, float $value)
    {
        $offset = (int) round($value * 48.0); // ±48 byte units at the extremes
        $r = $b = [];
        for ($i = 0; $i <= 255; $i++) {
            $r[$i] = self::clamp($i + $offset);
            $b[$i] = self::clamp($i - $offset);
        }
        $g = [];
        for ($i = 0; $i <= 255; $i++) {
            $g[$i] = $i;
        }

        return self::applyLut($image, $r, $g, $b);
    }

    /**
     * Highlight recovery: pulls the brightest tones back down toward detail
     * while leaving midtones/shadows untouched (positive recovers).
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private static function applyHighlightRecovery($image, float $value)
    {
        $strength = max(0.0, $value); // negative recovery = no-op
        $r = $g = $b = [];
        for ($i = 0; $i <= 255; $i++) {
            // Weight grows quadratically above mid-gray: highlight-only.
            $w = $i > 127 ? (($i - 127) / 128.0) ** 2 * $strength : 0.0;
            $recovered = (int) round($i - $i * $w * 0.5);
            $r[$i] = $g[$i] = $b[$i] = self::clamp($recovered);
        }

        return self::applyLut($image, $r, $g, $b);
    }

    /**
     * Shadow lift: raises the darkest tones while leaving highlights
     * untouched (positive lifts).
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private static function applyShadowLift($image, float $value)
    {
        $strength = max(0.0, $value);
        $r = $g = $b = [];
        for ($i = 0; $i <= 255; $i++) {
            // Weight grows quadratically below mid-gray: shadow-only.
            $w = $i < 127 ? ((127 - $i) / 128.0) ** 2 * $strength : 0.0;
            $lifted = (int) round($i + (255 - $i) * $w * 0.6);
            $r[$i] = $g[$i] = $b[$i] = self::clamp($lifted);
        }

        return self::applyLut($image, $r, $g, $b);
    }

    /**
     * Apply per-channel lookup tables in one pass.
     *
     * @param  resource|\GdImage  $image
     * @param  array<int, int>  $r
     * @param  array<int, int>  $g
     * @param  array<int, int>  $b
     * @return resource|\GdImage
     */
    private static function applyLut($image, array $r, array $g, array $b)
    {
        // imageflip-free path: strip alpha via a fresh truecolor canvas.
        $width = imagesx($image);
        $height = imagesy($image);
        $out = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $nr = $r[($rgb >> 16) & 0xFF];
                $ng = $g[($rgb >> 8) & 0xFF];
                $nb = $b[$rgb & 0xFF];

                imagesetpixel($out, $x, $y, ($nr << 16) | ($ng << 8) | $nb);
            }
        }

        if (is_resource($image) || $image instanceof \GdImage) {
            imagedestroy($image);
        }

        return $out;
    }
}
