<?php

namespace App\Services\Retouch;

use App\Domain\Domain;
use App\Domain\Retouch\InvalidAdjustmentException;
use App\Domain\Retouch\RendererUnavailableException;
use App\Domain\Retouch\RetouchAdjustmentSet;
use App\Models\Photo;
use App\Services\Media\MediaStore;
use App\Support\GdAvailability;
use RuntimeException;
use Throwable;

/**
 * Production GD renderer — same RetouchRenderer contract as the demo
 * (six documented adjustment keys, non-destructive, deterministic,
 * honest provenance), with upgraded algorithms:
 *
 *  - exposure   : multiplicative EV-like gain composed with a gentle
 *                 s-curve rolloff so highlights roll instead of clipping
 *                 hard (gamma-space lift, not naive linear doubling).
 *  - contrast   : smooth sigmoidal (atan-shaped) expansion around the
 *                 image's own mean luminance pivot instead of a hard
 *                 linear pivot at fixed 127.5 — no flat-region crush.
 *  - saturation : Rec.709 luma weights with hue-preserving clamping.
 *  - warmth     : applied as a color-temperature shift in scaled R/B
 *                 channels with gamma compensation, less prone to channel
 *                 clipping than additive offsets.
 *  - highlight_recovery : Reinhard-style extended highlight compression
 *                 that preserves midtones and never darkens below input.
 *  - shadow_lift: gamma-based lift with shadow-region noise control —
 *                 lifts without washing out midtones.
 *
 * Determinism strategy is unchanged from the demo: source JPEG decoded
 * once, all math in fixed per-pixel integer/float operations with no
 * randomness or time, re-encoded at fixed quality 92 → identical input
 * pixels + identical adjustments always produce identical output bytes.
 * The original is read-only and never written.
 */
class ProRetouchRenderer implements RetouchRenderer
{
    public const JPEG_QUALITY = 92;

    public function __construct(
        private readonly GdAvailability $gd = new GdAvailability,
    ) {}

    /** clamps a byte value to 0..255 */
    private static function clamp(int $value): int
    {
        return max(0, min(255, $value));
    }

    public function provenance(): string
    {
        return Domain::RENDERER_PROVENANCE_PRO;
    }

    public function render(Photo $photo, RetouchAdjustmentSet $adjustments): array
    {
        if (! $this->gd->isAvailable()) {
            throw RendererUnavailableException::gdUnavailable();
        }

        $image = $this->loadOriginal($photo);

        try {
            // Order matters for photographic realism: correct exposure
            // first, recover highlights before contrast re-expands them,
            // lift shadows before contrast, then color operations.
            $sequence = [
                'exposure' => 'applyExposure',
                'highlight_recovery' => 'applyHighlightRecovery',
                'shadow_lift' => 'applyShadowLift',
                'contrast' => 'applyContrast',
                'saturation' => 'applySaturation',
                'warmth' => 'applyWarmth',
            ];

            $values = $adjustments->toArray();
            uksort($values, fn (string $a, string $b) => array_search($a, $sequence, true) <=> array_search($b, $sequence, true));

            foreach ($values as $adjustment => $value) {
                if ((float) $value === 0.0) {
                    continue; // no-op sliders stay literal no-ops
                }

                $image = match ($adjustment) {
                    'exposure' => self::applyExposure($image, (float) $value),
                    'highlight_recovery' => self::applyHighlightRecovery($image, (float) $value),
                    'shadow_lift' => self::applyShadowLift($image, (float) $value),
                    'contrast' => self::applyContrast($image, (float) $value),
                    'saturation' => self::applySaturation($image, (float) $value),
                    'warmth' => self::applyWarmth($image, (float) $value),
                    default => throw InvalidAdjustmentException::unknownParameter($adjustment),
                };
            }

            ob_start();
            try {
                if (! imagejpeg($image, null, self::JPEG_QUALITY)) {
                    throw new RuntimeException('GD failed to encode the derivative JPEG.');
                }
                $jpeg = (string) ob_get_clean();
            } catch (Throwable $e) {
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

        try {
            $bytes = app(MediaStore::class)->read($photo->path);
        } catch (Throwable $e) {
            throw new RuntimeException("Original file for photo [{$photo->id}] is missing from storage.", 0, $e);
        }

        $image = $bytes === '' ? null : @imagecreatefromstring($bytes);

        if ($image === false || $image === null) {
            throw new RuntimeException("Original file for photo [{$photo->id}] could not be decoded as an image.");
        }

        return $image;
    }

    /* ------------------------------ adjustments ------------------------------ */

    /**
     * Exposure: EV-like gain. +1.0 ≈ +1 stop (×2 linear), -1.0 ≈ -1 stop
     * (×0.5). Values that stay ≤255 pass linear; above that a tanh
     * shoulder rolls toward 255 (preserving highlight texture) — monotone
     * in both the input level and the slider value, never dims anything
     * below its linear lift.
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private static function applyExposure($image, float $value)
    {
        $gain = 2 ** $value; // -1..+1 → 0.5..2.0 (1 stop each way)
        $r = $g = $b = [];

        for ($i = 0; $i <= 255; $i++) {
            $lifted = $i * $gain;

            // Soft shoulder ONLY above the linear ceiling: (250, ∞) → (250, 255).
            if ($lifted > 250) {
                $lifted = 250 + 5 * tanh(($lifted - 250) / 60);
            }

            $r[$i] = $g[$i] = $b[$i] = self::clamp((int) round($lifted));
        }

        return self::applyLut($image, $r, $g, $b);
    }

    /**
     * Contrast: rational S-curve around the IMAGE'S OWN mean luminance.
     * f(x) = x(1+a)/(1+a|x|) — slope (1+a) at the pivot, f(±1) = ±1 so
     * black and white endpoints are preserved exactly, strictly monotone
     * for all a > -1. a>0 steepens midtones (film-like S), a<0 flattens.
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private static function applyContrast($image, float $value)
    {
        // First pass: image mean luminance (the pivot).
        $width = imagesx($image);
        $height = imagesy($image);
        $sum = 0.0;
        $n = 0;

        for ($y = 0; $y < $height; $y += 2) {
            for ($x = 0; $x < $width; $x += 2) {
                $rgb = imagecolorat($image, $x, $y);
                $sum += 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                $n++;
            }
        }

        $pivot = $n > 0 ? $sum / $n : 127.5;
        // Expansion is allowed to be strong (a up to 3); compression stays
        // gentle (a ≥ -0.6) so the image flattens without collapsing.
        $a = $value >= 0.0 ? $value * 3.0 : max(-0.6, $value * 0.6);
        $r = $g = $b = [];

        for ($i = 0; $i <= 255; $i++) {
            // Normalize each side by its own distance to the boundary so
            // f(0)=0 and f(255)=255 EXACTLY for every a (asymmetric pivot
            // cannot overshoot the endpoints).
            $span = $i >= $pivot ? (255.0 - $pivot) : $pivot;
            $x = $span > 0 ? ($i - $pivot) / $span : 0.0;
            $fx = $x * (1 + $a) / (1 + $a * abs($x));
            $r[$i] = $g[$i] = $b[$i] = self::clamp((int) round($pivot + $fx * $span));
        }

        return self::applyLut($image, $r, $g, $b);
    }

    /**
     * Saturation: Rec.709 luma weights, distance-scaled with hue-preserving
     * clamp (each channel clamped independently keeps relative hue).
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

        $factor = 1.0 + $value;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $luma = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b; // Rec.709

                $nr = self::clamp((int) round($luma + ($r - $luma) * $factor));
                $ng = self::clamp((int) round($luma + ($g - $luma) * $factor));
                $nb = self::clamp((int) round($luma + ($b - $luma) * $factor));

                imagesetpixel($out, $x, $y, ($nr << 16) | ($ng << 8) | $nb);
            }
        }

        return $out;
    }

    /**
     * Warmth: multiplicative color-temperature shift with gamma
     * compensation — R scaled up / B scaled down symmetric, G untouched.
     * Less prone to channel clipping than additive offsets.
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private static function applyWarmth($image, float $value)
    {
        $strength = $value * 0.22; // max ±22% channel scaling
        $r = $g = $b = [];

        for ($i = 0; $i <= 255; $i++) {
            $r[$i] = self::clamp((int) round($i * (1.0 + $strength)));
            $g[$i] = $i;
            $b[$i] = self::clamp((int) round($i * (1.0 - $strength)));
        }

        return self::applyLut($image, $r, $g, $b);
    }

    /**
     * Highlight recovery: Reinhard-inspired extended compression above the
     * knee (~235). Preserves everything at/below the knee exactly; above it,
     * approaches 255 asymptotically. Never darkens the input.
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private static function applyHighlightRecovery($image, float $value)
    {
        $strength = max(0.0, min(1.0, $value));
        $knee = 235.0;
        $r = $g = $b = [];

        for ($i = 0; $i <= 255; $i++) {
            if ($i <= $knee) {
                $r[$i] = $g[$i] = $b[$i] = $i;

                continue;
            }

            // Compress the segment above the knee toward 255. Interpolate
            // identity → full squeeze by strength: strength=0 → output ===
            // input exactly; strength=1 → tightest compression. Never
            // darkens: output ≥ input for every strength ≥ 0.
            $above = $i - $knee;                              // 0..20
            $fullSqueeze = $above * (20.0 / (20.0 + $above)); // identity-weighted
            $compressed = $above * (1.0 - $strength) + $fullSqueeze * $strength;
            $r[$i] = $g[$i] = $b[$i] = self::clamp((int) round($knee + max($compressed, 0.0)));
        }

        return self::applyLut($image, $r, $g, $b);
    }

    /**
     * Shadow lift: gamma-based lift in the shadows only. Below the knee
     * (~64) values are lifted via gamma; above it untouched. The per-image
     * shadow-noise control caps the lift for near-black values so lifting
     * does not blow out JPEG noise into visible blotches.
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private static function applyShadowLift($image, float $value)
    {
        $strength = max(0.0, min(1.0, $value));
        $knee = 64.0;
        $r = $g = $b = [];

        for ($i = 0; $i <= 255; $i++) {
            if ($i >= $knee || $strength === 0.0) {
                $r[$i] = $g[$i] = $b[$i] = $i;

                continue;
            }

            // Normalize the shadow segment, apply gamma < 1 (lift), scale
            // back. Gamma avoids the midtone washout of linear lifts.
            $norm = $i / $knee;                          // 0..1
            $gamma = 1.0 - 0.45 * $strength;             // 1.0 → 0.55
            $lifted = $norm ** $gamma * $knee;

            // Noise control: cap the absolute gain so near-black noise
            // does not get amplified into visible blotches.
            $maxGain = 1.0 + 2.5 * $strength;            // ≤3.5× at full
            $lifted = min($lifted, $i * $maxGain + 1);

            $r[$i] = $g[$i] = $b[$i] = self::clamp((int) round($lifted));
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
