<?php

namespace App\Domain\Culling;

/**
 * Application-level representation of ONE photo's structured observations.
 *
 * This is the Sprint 3 photo-analysis contract: the shape a
 * PhotoAnalysisProvider produces and ContextAwareCullingService consumes.
 *
 * Two strictly separated sections:
 *  - technical : observable image qualities (sharpness, exposure, …)
 *  - creative  : qualities only meaningful against a creative intent
 *
 * Every assessment carries a confidence. Uncertain analysis is never
 * presented as fact.
 *
 * Observations are DATA, not decisions — they never change selection_state.
 *
 * @phpstan-type Assessment array{assessment: string, confidence: float}
 * @phpstan-type Technical array{sharpness: Assessment, exposure: Assessment, motion_blur: Assessment, highlight_clipping: Assessment, eyes_open?: Assessment|null}
 * @phpstan-type Creative array{expression: string, candidness: string, environmental_storytelling: string, mood: list<string>, compositional_fit: string, emotion_strength: string}
 */
final class PhotoObservation
{
    /**
     * @param  array<string, mixed>  $technical
     * @param  array<string, mixed>  $creative
     */
    private function __construct(
        public readonly int $photoId,
        public readonly array $technical,
        public readonly array $creative,
        public readonly string $provider,
        public readonly string $provenance,
        public readonly ?string $similarityGroup,
    ) {}

    /**
     * Build from a provider payload array (the photo_observations.payload JSON).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(int $photoId, array $payload, string $provider, string $provenance, ?string $similarityGroup = null): self
    {
        return new self(
            photoId: $photoId,
            technical: $payload['technical'] ?? [],
            creative: $payload['creative'] ?? [],
            provider: $provider,
            provenance: $provenance,
            similarityGroup: $similarityGroup,
        );
    }

    /** @return array<string, mixed> full JSON-ready payload (matches stored shape) */
    public function toArray(): array
    {
        return [
            'technical' => $this->technical,
            'creative' => $this->creative,
        ];
    }

    /* ------------------------- technical accessors ------------------------- */

    /** @return Assessment|null */
    public function sharpness(): ?array
    {
        return $this->technical['sharpness'] ?? null;
    }

    /** @return Assessment|null */
    public function exposure(): ?array
    {
        return $this->technical['exposure'] ?? null;
    }

    /** @return Assessment|null */
    public function motionBlur(): ?array
    {
        return $this->technical['motion_blur'] ?? null;
    }

    /** @return Assessment|null */
    public function highlightClipping(): ?array
    {
        return $this->technical['highlight_clipping'] ?? null;
    }

    /** @return Assessment|null */
    public function eyesOpen(): ?array
    {
        return $this->technical['eyes_open'] ?? null;
    }

    /** Human phrase for the sharpest concern in the technical section. */
    public function technicalSummary(): string
    {
        $bits = [];
        $sharp = $this->sharpness();
        if ($sharp !== null && in_array($sharp['assessment'], ['soft', 'slightly_soft'], true)) {
            $bits[] = $sharp['assessment'] === 'soft' ? 'soft' : 'slightly soft';
        }
        $blur = $this->motionBlur();
        if ($blur !== null && in_array($blur['assessment'], ['mild', 'strong'], true)) {
            $bits[] = $blur['assessment'] === 'strong' ? 'strong motion blur' : 'slight motion blur';
        }
        $clip = $this->highlightClipping();
        if ($clip !== null && $clip['assessment'] === 'risk') {
            $bits[] = 'highlights near clipping';
        }
        $exp = $this->exposure();
        if ($exp !== null && in_array($exp['assessment'], ['underexposed', 'overexposed'], true)) {
            $bits[] = $exp['assessment'];
        }

        return $bits === [] ? 'technically sound' : implode(', ', $bits);
    }

    /* -------------------------- creative accessors -------------------------- */

    public function expression(): string
    {
        return (string) ($this->creative['expression'] ?? 'unknown');
    }

    public function candidness(): string
    {
        return (string) ($this->creative['candidness'] ?? 'unknown');
    }

    public function environmentalStorytelling(): string
    {
        return (string) ($this->creative['environmental_storytelling'] ?? 'unknown');
    }

    /** @return list<string> */
    public function mood(): array
    {
        return array_values((array) ($this->creative['mood'] ?? []));
    }

    public function emotionStrength(): string
    {
        return (string) ($this->creative['emotion_strength'] ?? $this->creative['expression'] ?? 'unknown');
    }

    /* ------------------------------ serialization ------------------------------ */

    /**
     * JSON-ready shape for API/WebMCP responses (observations only — never a
     * decision).
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'photo_id' => $this->photoId,
            'technical' => $this->technical,
            'creative' => $this->creative,
            'provider' => $this->provider,
            'provenance' => $this->provenance,
            'similarity_group' => $this->similarityGroup,
        ];
    }
}
