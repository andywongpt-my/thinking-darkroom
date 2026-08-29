<?php

namespace App\Services\Culling;

use App\Domain\Culling\PhotoObservation;
use App\Domain\Domain;
use App\Models\Photo;
use App\Services\Media\MediaStore;
use App\Support\GdAvailability;
use Throwable;

/**
 * Deterministic demo analysis provider — the honest hackathon implementation.
 *
 * What it REALLY does: opens the stored image with GD, downsamples it, and
 * computes classical pixel statistics (Laplacian-style local contrast for
 * sharpness, histogram spread + mean level for exposure, highlight-clipping
 * fraction, frame-difference for the duplicate group). These are observable
 * image characteristics — no vision model, no ML, no external API, fully
 * deterministic for a given file.
 *
 * What it does NOT do: faces, expressions, candidness. For a portrait demo
 * those CREATIVE observations must come from somewhere — so a photo may carry
 * a JSON sidecar file (same path, `.obs.json` suffix) with photographer- or
 * curator-authored creative labels. When the sidecar is absent the creative
 * section is honestly reported as `unobserved` rather than invented.
 *
 * This keeps the demo dataset legally safe (Andy-owned/generated images +
 * human-authored creative annotations) and the analysis honestly attributed.
 */
class DemoPhotoAnalysisProvider implements PhotoAnalysisProvider
{
    public function __construct(
        private readonly GdAvailability $gd = new GdAvailability,
    ) {}

    public function name(): string
    {
        return Domain::OBSERVATION_PROVIDER_DEMO;
    }

    public function provenance(): string
    {
        return $this->gd->isAvailable()
            ? Domain::OBSERVATION_PROVENANCES[Domain::OBSERVATION_PROVIDER_DEMO]
            : Domain::OBSERVATION_PROVENANCE_DEMO_GD_UNAVAILABLE;
    }

    public function observe(Photo $photo): PhotoObservation
    {
        $technical = $this->technicalFromPixels($photo);
        $creative = $this->creativeFromSidecar($photo);
        $similarityGroup = $this->similarityGroupFromPixels($photo, $technical);

        return PhotoObservation::fromArray(
            $photo->id,
            ['technical' => $technical, 'creative' => $creative],
            $this->name(),
            $this->provenance(),
            $similarityGroup,
        );
    }

    /* --------------------------------- technical --------------------------------- */

    /**
     * Classical deterministic pixel statistics.
     *
     * @return array<string, mixed>
     */
    private function technicalFromPixels(Photo $photo): array
    {
        $image = $this->loadImage($photo);

        if ($image === null) {
            // No analyzable pixels (e.g. test fixtures with placeholder paths):
            // report unknowns honestly instead of pretending.
            return $this->unknownTechnical();
        }

        $gray = $this->grayscaleDownsample($image, 256);
        imagedestroy($image);

        return [
            'sharpness' => [
                'assessment' => $this->sharpnessAssessment($gray),
                'confidence' => 0.86,
            ],
            'exposure' => [
                'assessment' => $this->exposureAssessment($gray),
                'confidence' => 0.92,
            ],
            'motion_blur' => [
                'assessment' => $this->motionBlurAssessment($gray),
                'confidence' => 0.78,
            ],
            'highlight_clipping' => [
                'assessment' => $this->highlightAssessment($gray),
                'confidence' => 0.9,
            ],
            'eyes_open' => null, // not claimed — no face model in the demo provider
        ];
    }

    /** @return resource|\GdImage|null */
    private function loadImage(Photo $photo)
    {
        if (! $this->gd->isAvailable()) {
            return null;
        }

        if (! $photo->path) {
            return null;
        }

        // Route byte access through MediaStore so durable Vercel Blob URLs
        // (stored as absolute http paths) resolve exactly like local disks.
        try {
            $bytes = app(MediaStore::class)->read($photo->path);
        } catch (Throwable) {
            return null;
        }

        if ($bytes === '') {
            return null;
        }

        return @imagecreatefromstring($bytes) ?: null;
    }

    /**
     * Downsample to a grayscale grid and return raw luminance values.
     *
     * @param  resource|\GdImage  $image
     * @return list<float>
     */
    private function grayscaleDownsample($image, int $size): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $small = imagecreatetruecolor($size, $size);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $size, $size, $width, $height);

        $values = [];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $values[] = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            }
        }
        imagedestroy($small);

        return $values;
    }

    /**
     * Local-contrast proxy (4-neighbour Laplacian energy, normalized).
     *
     * @param  list<float>  $gray
     */
    private function laplacianEnergy(array $gray, int $size = 256): float
    {
        $energy = 0.0;
        $count = 0;
        for ($y = 1; $y < $size - 1; $y += 2) {
            for ($x = 1; $x < $size - 1; $x += 2) {
                $i = $y * $size + $x;
                $lap = abs(4 * $gray[$i] - $gray[$i - 1] - $gray[$i + 1] - $gray[$i - $size] - $gray[$i + $size]);
                $energy += $lap;
                $count++;
            }
        }

        return $count > 0 ? $energy / $count : 0.0;
    }

    /** @param  list<float>  $gray */
    private function sharpnessAssessment(array $gray): string
    {
        $energy = $this->laplacianEnergy($gray);

        // Thresholds tuned on the demo dataset (synthetic images with baked
        // blur levels); deterministic for any given file.
        return match (true) {
            $energy >= 8.0 => 'sharp',
            $energy >= 3.5 => 'slightly_soft',
            default => 'soft',
        };
    }

    /** @param  list<float>  $gray */
    private function exposureAssessment(array $gray): string
    {
        $mean = array_sum($gray) / count($gray);

        return match (true) {
            $mean < 45 => 'underexposed',
            $mean > 215 => 'overexposed',
            default => 'acceptable',
        };
    }

    /**
     * Motion-blur proxy: directional gradient asymmetry (blur smears edges,
     * collapsing energy in the smear direction).
     *
     * @param  list<float>  $gray
     */
    private function motionBlurAssessment(array $gray, int $size = 256): string
    {
        $horizontal = 0.0;
        $count = 0;
        for ($y = 1; $y < $size - 1; $y += 3) {
            for ($x = 1; $x < $size - 1; $x += 3) {
                $i = $y * $size + $x;
                $horizontal += abs($gray[$i] - $gray[$i - 1]) + abs($gray[$i] - $gray[$i + 1]);
                $count++;
            }
        }
        $horizontalEnergy = $count > 0 ? $horizontal / $count : 0.0;

        return match (true) {
            $horizontalEnergy < 1.1 => 'strong',
            $horizontalEnergy < 2.2 => 'mild',
            default => 'none',
        };
    }

    /** @param  list<float>  $gray */
    private function highlightAssessment(array $gray): string
    {
        $clipped = count(array_filter($gray, fn ($v) => $v >= 250));

        return ($clipped / count($gray)) > 0.08 ? 'risk' : 'safe';
    }

    /** @return array<string, mixed> */
    private function unknownTechnical(): array
    {
        $unknown = fn (): array => ['assessment' => 'unknown', 'confidence' => 0.0];

        return [
            'sharpness' => $unknown(),
            'exposure' => $unknown(),
            'motion_blur' => $unknown(),
            'highlight_clipping' => $unknown(),
            'eyes_open' => null,
        ];
    }

    /* --------------------------------- creative --------------------------------- */

    /**
     * Creative labels come ONLY from a human-authored sidecar — never
     * invented. Missing sidecar → honestly unobserved.
     *
     * @return array<string, mixed>
     */
    private function creativeFromSidecar(Photo $photo): array
    {
        $sidecar = $this->sidecarPayload($photo);
        $creative = $sidecar['creative'] ?? null;

        if (! is_array($creative)) {
            return [
                'expression' => 'unobserved',
                'candidness' => 'unobserved',
                'environmental_storytelling' => 'unobserved',
                'mood' => [],
                'compositional_fit' => 'unobserved',
                'emotion_strength' => 'unobserved',
            ];
        }

        return [
            'expression' => (string) ($creative['expression'] ?? 'unobserved'),
            'candidness' => (string) ($creative['candidness'] ?? 'unobserved'),
            'environmental_storytelling' => (string) ($creative['environmental_storytelling'] ?? 'unobserved'),
            'mood' => array_values((array) ($creative['mood'] ?? [])),
            'compositional_fit' => (string) ($creative['compositional_fit'] ?? 'unobserved'),
            'emotion_strength' => (string) ($creative['emotion_strength'] ?? $creative['expression'] ?? 'unobserved'),
        ];
    }

    /** @return array<string, mixed> */
    private function sidecarPayload(Photo $photo): array
    {
        if (! $photo->path) {
            return [];
        }

        // Sidecars live next to the original bytes — same MediaStore dual
        // path (durable http vs local disk).
        $sidecarPath = $photo->path.'.obs.json';
        try {
            $raw = app(MediaStore::class)->read($sidecarPath);
        } catch (Throwable) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /* ------------------------------- similarity -------------------------------- */

    /**
     * Lightweight duplicate grouping: classic mean-sign perceptual hash —
     * each 8x8 cell is compared against the image's own mean luminance
     * (1 = brighter, 0 = darker), joined into a 64-bit fingerprint. Grouping
     * uses a small Hamming radius so near-identical burst frames (whose
     * border cells flip under JPEG noise) collide into one group, while
     * genuinely different compositions (distance ≥ 6) stay apart.
     *
     * Implementation: the fingerprint ITSELF is the group id (no one-way
     * hash) and analysis walks the project's photos in id order, so a photo
     * joins the group of the FIRST photo whose fingerprint is within
     * SIMILARITY_RADIUS. Deterministic for a given set of files.
     *
     * @param  array<string, mixed>  $technical
     */
    private function similarityGroupFromPixels(Photo $photo, array $technical): ?string
    {
        $image = $this->loadImage($photo);
        if ($image === null) {
            return $this->sidecarPayload($photo)['similarity_group'] ?? null;
        }

        $gray = $this->grayscaleDownsample($image, 8);
        imagedestroy($image);

        $mean = array_sum($gray) / count($gray);
        $hash = '';
        foreach ($gray as $v) {
            $hash .= $v >= $mean ? '1' : '0';
        }

        // Compare against fingerprints already computed in this analysis run.
        foreach (self::$runFingerprints as $groupId => $bits) {
            if ($this->hammingDistance($hash, $bits) <= self::SIMILARITY_RADIUS) {
                return $groupId;
            }
        }

        $groupId = 'h'.substr(hash('sha256', $hash), 0, 16);
        self::$runFingerprints[$groupId] = $hash;

        return $groupId;
    }

    /** Hamming radius under which two frames count as similar/duplicate. */
    private const SIMILARITY_RADIUS = 2;

    /**
     * Fingerprints computed during the current analysis run
     * (groupId → 64-bit string). Reset by observeBatch-aware callers; a
     * static cache is safe here because analysis is single-pass per project.
     *
     * @var array<string, string>
     */
    private static array $runFingerprints = [];

    /** Clear the per-run fingerprint memory (call before a fresh analyze). */
    public static function resetSimilarityMemory(): void
    {
        self::$runFingerprints = [];
    }

    private function hammingDistance(string $a, string $b): int
    {
        $d = 0;
        $len = min(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] !== $b[$i]) {
                $d++;
            }
        }

        return $d;
    }
}
