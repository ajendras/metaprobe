<?php

declare(strict_types=1);

namespace MetaProbe;

use JsonException;
use MetaProbe\Security\Scanner;

/**
 * Immutable Data Transfer Object holding all extracted metadata.
 *
 * Provides typed accessors, GPS value object, structured array/JSON output,
 * and a privacy score computed via the Security Scanner.
 */
final class Metadata
{
    /**
     * @param array<string, mixed> $exif Normalized EXIF data
     * @param array<string, mixed> $iptc Normalized IPTC data
     * @param array<string, mixed> $xmp Normalized XMP data
     */
    public function __construct(
        private readonly array $exif = [],
        private readonly array $iptc = [],
        private readonly array $xmp = [],
        private readonly ?Gps $gps = null,
    ) {
    }

    /**
     * Returns the raw normalized EXIF metadata.
     *
     * @return array<string, mixed>
     */
    public function getExif(): array
    {
        return $this->exif;
    }

    /**
     * Returns the raw normalized IPTC metadata.
     *
     * @return array<string, mixed>
     */
    public function getIptc(): array
    {
        return $this->iptc;
    }

    /**
     * Returns the raw normalized XMP metadata.
     *
     * @return array<string, mixed>
     */
    public function getXmp(): array
    {
        return $this->xmp;
    }

    /**
     * Returns the parsed GPS coordinates or null if not present.
     */
    public function getGps(): ?Gps
    {
        return $this->gps;
    }

    /**
     * Returns a privacy/safety score between 0 and 100 (higher is safer).
     *
     * Score is computed by the Security Scanner on every call. The value
     * is deterministic for a given Metadata instance.
     */
    public function getPrivacyScore(): int
    {
        return (new Scanner())->calculateScore($this);
    }

    /**
     * Returns a complete array representation of the metadata.
     *
     * @return array{
     *   exif: array<string, mixed>,
     *   iptc: array<string, mixed>,
     *   xmp: array<string, mixed>,
     *   gps: array{lat: float, lng: float}|null,
     *   google_maps_url: string|null,
     *   privacy_score: int
     * }
     */
    public function toArray(): array
    {
        return [
            'exif' => $this->exif,
            'iptc' => $this->iptc,
            'xmp' => $this->xmp,
            'gps' => $this->gps !== null ? $this->gps->getDecimal() : null,
            'google_maps_url' => $this->gps?->getGoogleMapsUrl(),
            'privacy_score' => $this->getPrivacyScore(),
        ];
    }

    /**
     * Returns a pretty-printed JSON representation of the metadata.
     *
     * @throws JsonException When json_encode fails (should never happen with valid data)
     */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
