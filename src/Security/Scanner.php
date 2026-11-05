<?php

declare(strict_types=1);

namespace MetaProbe\Security;

use MetaProbe\Metadata;

/**
 * Scans metadata for sensitive information and calculates a privacy score.
 *
 * Identifies fields that may leak PII (GPS, camera fingerprints, author data)
 * and performs content-based steganography detection when an image path is supplied.
 */
final class Scanner
{
    private SteganographyDetector $stegoDetector;

    public function __construct()
    {
        $this->stegoDetector = new SteganographyDetector();
    }

    /**
     * Returns a list of human-readable privacy and security risks.
     *
     * When $imagePath is supplied, LSB steganography analysis is also performed.
     *
     * @param Metadata $metadata
     * @param string|null $imagePath
     * @return list<string>
     */
    public function getRisks(Metadata $metadata, ?string $imagePath = null): array
    {
        $risks = [];
        $exif = $metadata->getExif();
        $iptc = $metadata->getIptc();
        $xmp = $metadata->getXmp();
        $gps = $metadata->getGps();

        if ($gps !== null) {
            $risks[] = 'Embedded GPS coordinates';
        }

        if (!empty($exif['Camera']) || !empty($exif['Make']) || !empty($exif['Model'])) {
            $risks[] = 'Camera/device fingerprint present';
        }

        if (!empty($exif['SerialNumber']) || !empty($exif['OwnerName']) || !empty($exif['Artist'])) {
            $risks[] = 'Device serial number or owner information';
        }

        if (!empty($iptc['author']) || !empty($xmp['Creator']) || !empty($iptc['copyright'])) {
            $risks[] = 'Creator or author PII';
        }

        if (!empty($exif['Software'])) {
            $risks[] = 'Editing software metadata';
        }

        if ($imagePath !== null) {
            $stego = $this->stegoDetector->analyze($imagePath);
            $risks = array_merge($risks, $stego['risks']);
        }

        return $risks;
    }

    /**
     * Calculates a privacy score (0-100). Higher is safer.
     *
     * Each risk deducts points; LSB steganography adds an extra penalty when
     * an image path is supplied.
     */
    public function calculateScore(Metadata $metadata, ?string $imagePath = null): int
    {
        $risks = $this->getRisks($metadata, $imagePath);
        $deduction = count($risks) * 18;

        if ($imagePath !== null) {
            $stego = $this->stegoDetector->analyze($imagePath);
            $deduction += $stego['scorePenalty'] ?? 0;
        }

        return max(0, min(100, 100 - $deduction));
    }

    public function isSensitive(Metadata $metadata): bool
    {
        return $this->calculateScore($metadata) < 60;
    }
}
