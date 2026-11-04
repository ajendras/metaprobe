<?php

declare(strict_types=1);

namespace MetaProbe;

use InvalidArgumentException;

/**
 * Immutable value object representing GPS coordinates extracted from EXIF.
 *
 * Handles conversion from DMS (degrees/minutes/seconds) rational values
 * commonly found in EXIF GPS blocks to decimal degrees.
 */
final class Gps
{
    private function __construct(
        private readonly float $latitude,
        private readonly float $longitude
    ) {
    }

    /**
     * Creates a Gps instance from raw EXIF GPS data.
     *
     * Accepts both flat EXIF arrays and nested GPS sub-arrays. Handles
     * rational string values (e.g. "51/1", "30/1", "0/1").
     *
     * @param array<string, mixed> $raw Raw EXIF data containing GPS keys
     * @return self
     * @throws InvalidArgumentException When GPS data is missing or malformed
     */
    public static function fromExif(array $raw): self
    {
        $gpsData = $raw['GPS'] ?? $raw;

        $latRef = $gpsData['GPSLatitudeRef'] ?? $gpsData['LatitudeRef'] ?? 'N';
        $lngRef = $gpsData['GPSLongitudeRef'] ?? $gpsData['LongitudeRef'] ?? 'E';
        $latDms = $gpsData['GPSLatitude'] ?? $gpsData['Latitude'] ?? [];
        $lngDms = $gpsData['GPSLongitude'] ?? $gpsData['Longitude'] ?? [];

        if (!is_array($latDms)) {
            $latDms = [$latDms];
        }
        if (!is_array($lngDms)) {
            $lngDms = [$lngDms];
        }

        if (empty($latDms) || empty($lngDms)) {
            throw new InvalidArgumentException('GPS coordinate data is missing or invalid.');
        }

        $latitude = self::dmsToDecimal($latDms, $latRef);
        $longitude = self::dmsToDecimal($lngDms, $lngRef);

        return new self($latitude, $longitude);
    }

    /**
     * Converts DMS components to decimal degrees, applying hemisphere sign.
     *
     * @param array<int, string|float|int> $dms Array of degree, minute, second values (as rationals)
     * @param string $hemisphere N/S or E/W reference
     * @return float Decimal degree value
     */
    private static function dmsToDecimal(array $dms, string $hemisphere): float
    {
        $degrees = self::rationalToFloat($dms[0] ?? 0);
        $minutes = self::rationalToFloat($dms[1] ?? 0);
        $seconds = self::rationalToFloat($dms[2] ?? 0);

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        $hemisphere = strtoupper($hemisphere);
        if ($hemisphere === 'S' || $hemisphere === 'W') {
            $decimal = -$decimal;
        }

        return round($decimal, 8);
    }

    /**
     * Converts a rational string (e.g. "51/1") or numeric value to float.
     */
    private static function rationalToFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $str = (string) $value;
        if (str_contains($str, '/')) {
            $parts = explode('/', $str, 2);
            if (count($parts) === 2 && (float) $parts[1] !== 0.0) {
                return (float) $parts[0] / (float) $parts[1];
            }
        }

        return (float) $value;
    }

    /**
     * Returns decimal latitude and longitude.
     *
     * @return array{lat: float, lng: float}
     */
    public function getDecimal(): array
    {
        return [
            'lat' => $this->latitude,
            'lng' => $this->longitude,
        ];
    }

    /**
     * Returns a Google Maps URL for these coordinates.
     */
    public function getGoogleMapsUrl(): string
    {
        return sprintf(
            'https://www.google.com/maps?q=%.7f,%.7f',
            $this->latitude,
            $this->longitude
        );
    }

    /**
     * Returns a comma-separated string of the decimal coordinates.
     */
    public function __toString(): string
    {
        return sprintf('%.7f,%.7f', $this->latitude, $this->longitude);
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }
}
