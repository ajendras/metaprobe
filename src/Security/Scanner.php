<?php

declare(strict_types=1);

namespace MetaProbe\Security;

use MetaProbe\Metadata;

/**
 * Analyzes extracted metadata for privacy and security risks.
 *
 * Identifies PII leaks (GPS, author info, device fingerprints) and
 * computes a normalized safety score between 0 and 100.
 */
final class Scanner
{
    /**
     * Common patterns for fields that may contain sensitive information.
     *
     * @var array<string,string>
     */
    private const SENSITIVE_PATTERNS = [
        'gps' => '/^(gps|latitude|longitude|gpslatitude|gpslongitude)/i',
        'author' => '/^(author|creator|artist|byline|copyright|credit|owner)/i',
        'device' => '/^(make|model|software|camera|devicename|serial)/i',
        'location' => '/^(city|state|province|country|location|address|position)/i',
    ];

    /**
     * Returns a list of human-readable risk descriptions.
     *
     * @return list<string>
     */
    public function getRisks(Metadata $metadata): array
    {
        $risks = [];
        $allData = array_merge(
            $metadata->getExif(),
            $metadata->getIptc(),
            $metadata->getXmp()
        );

        foreach ($allData as $key => $value) {
            if ($this->isSensitive($key, $value)) {
                $risks[] = $this->formatRiskMessage($key, $value);
            }
        }

        if ($metadata->getGps() !== null) {
            $risks[] = 'GPS coordinates present (high privacy risk)';
        }

        // Remove duplicate messages and reindex
        return array_values(array_unique($risks));
    }

    /**
     * Calculates a privacy safety score (0-100). Higher is safer.
     *
     * Scoring rules:
     * - Base: 100
     * - GPS present: -45
     * - Author/Creator data: -25
     * - Device fingerprint: -20
     * - Other location or PII: -15 per category
     */
    public function calculateScore(Metadata $metadata): int
    {
        $score = 100;
        $risks = $this->getRisks($metadata);
        $gps = $metadata->getGps();

        if ($gps !== null) {
            $score -= 45;
        }

        $hasAuthor = false;
        $hasDevice = false;
        $hasLocation = false;

        foreach ($risks as $risk) {
            $riskLower = strtolower($risk);
            if (str_contains($riskLower, 'author') || str_contains($riskLower, 'creator')) {
                $hasAuthor = true;
            }
            if (str_contains($riskLower, 'device') || str_contains($riskLower, 'fingerprint') || str_contains($riskLower, 'camera')) {
                $hasDevice = true;
            }
            if (str_contains($riskLower, 'location') || str_contains($riskLower, 'city') || str_contains($riskLower, 'country')) {
                $hasLocation = true;
            }
        }

        if ($hasAuthor) {
            $score -= 25;
        }
        if ($hasDevice) {
            $score -= 20;
        }
        if ($hasLocation && $gps === null) {
            $score -= 15;
        }

        // Additional penalty for multiple risks
        if (count($risks) > 2) {
            $score -= 10;
        }

        return max(0, min(100, $score));
    }

    /**
     * Determines if a metadata field/value pair represents a privacy risk.
     *
     * @param string $key Metadata key (e.g. "GPSLatitude", "author")
     * @param mixed $value The associated value
     */
    public function isSensitive(string $key, mixed $value): bool
    {
        if (empty($value)) {
            return false;
        }

        // Empty strings or empty arrays are not sensitive
        if (is_string($value) && trim($value) === '') {
            return false;
        }
        if (is_array($value) && count($value) === 0) {
            return false;
        }

        $keyLower = strtolower($key);

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $keyLower)) {
                return true;
            }
        }

        // Explicit GPS fields
        if (in_array($key, ['GPSLatitude', 'GPSLongitude', 'GPSLatitudeRef', 'GPSLongitudeRef', 'GPS'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Creates a user-friendly risk description for a sensitive field.
     */
    private function formatRiskMessage(string $key, mixed $value): string
    {
        $keyLower = strtolower($key);

        if (preg_match('/gps|latitude|longitude/i', $keyLower)) {
            return 'GPS coordinates present (high privacy risk)';
        }

        if (preg_match('/author|creator|artist|byline/i', $keyLower)) {
            $name = is_string($value) ? trim($value) : '(present)';
            return "Author/creator information detected: {$name}";
        }

        if (preg_match('/make|model|software|camera/i', $keyLower)) {
            return 'Device fingerprint detected (camera model/make)';
        }

        if (preg_match('/city|country|location|position/i', $keyLower)) {
            return 'Geographic location metadata present';
        }

        return "Sensitive metadata field '{$key}' detected";
    }
}
