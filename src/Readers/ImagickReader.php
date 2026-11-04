<?php

declare(strict_types=1);

namespace MetaProbe\Readers;

use MetaProbe\ReaderInterface;
use Throwable;

/**
 * Reads metadata from WebP, AVIF and HEIC images using the Imagick extension.
 *
 * Extracts properties via getImageProperties() and normalizes them into the
 * same shape expected by Metadata, Gps and the rest of the library (Camera,
 * Exposure, GPS fields, etc). Gracefully falls back when Imagick is unavailable.
 */
final class ImagickReader implements ReaderInterface
{
    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, ['image/webp', 'image/avif', 'image/heic'], true);
    }

    /**
     * Reads metadata using Imagick and returns a normalized array.
     *
     * @param string $path Path to the image file
     * @return array<string, mixed> Normalized metadata (merged into exif bucket)
     */
    public function read(string $path): array
    {
        if (!class_exists(\Imagick::class)) {
            return [];
        }

        try {
            $imagick = new \Imagick($path);
            $properties = $imagick->getImageProperties('*', true);
            $imagick->clear();
            $imagick->destroy();

            return $this->normalize($properties);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Normalizes Imagick properties into the same keys used by ExifReader.
     */
    private function normalize(array $properties): array
    {
        $normalized = [];

        // Pull all exif:* properties (most common for these formats)
        foreach ($properties as $key => $value) {
            if (str_starts_with($key, 'exif:')) {
                $exifKey = substr($key, 5);
                $normalized[$exifKey] = $value;
            }
        }

        // Additional mappings for formats that use other prefixes
        $extraMaps = [
            'tiff:Make' => 'Make',
            'tiff:Model' => 'Model',
            'png:Make' => 'Make',
            'png:Model' => 'Model',
            'heic:Make' => 'Make',
            'heic:Model' => 'Model',
        ];

        foreach ($extraMaps as $source => $target) {
            if (isset($properties[$source]) && !isset($normalized[$target])) {
                $normalized[$target] = $properties[$source];
            }
        }

        // Friendly computed fields (mirrors ExifReader)
        if (!empty($normalized['Make']) || !empty($normalized['Model'])) {
            $make = trim((string) ($normalized['Make'] ?? ''));
            $model = trim((string) ($normalized['Model'] ?? ''));
            $normalized['Camera'] = $make !== '' && $model !== ''
                ? $make . ' ' . $model
                : ($make ?: $model);
        }

        // Exposure summary
        if (isset($normalized['ExposureTime'], $normalized['FNumber'])) {
            $exposureTime = $normalized['ExposureTime'];
            $fNumber = $normalized['FNumber'];
            if (is_array($fNumber)) {
                $fNumber = implode('/', $fNumber);
            }
            $iso = $normalized['ISOSpeedRatings'] ?? $normalized['ISOSpeed'] ?? 'N/A';
            $normalized['Exposure'] = sprintf(
                '%s sec at f/%s (ISO %s)',
                $exposureTime,
                $fNumber,
                $iso
            );
        }

        // Date normalization
        if (isset($normalized['DateTimeOriginal'])) {
            $normalized['DateTime'] = $normalized['DateTimeOriginal'];
        } elseif (isset($normalized['DateTime'])) {
            $normalized['DateTimeOriginal'] = $normalized['DateTime'];
        }

        // GPS handling - ensure format works with updated Gps::fromExif()
        foreach (['GPSLatitude', 'GPSLongitude', 'GPSLatitudeRef', 'GPSLongitudeRef'] as $gpsKey) {
            $propKey = 'exif:' . $gpsKey;
            if (isset($properties[$propKey])) {
                $val = $properties[$propKey];
                if ($gpsKey === 'GPSLatitude' || $gpsKey === 'GPSLongitude') {
                    if (is_string($val) && str_contains($val, ',')) {
                        $normalized[$gpsKey] = array_map('trim', explode(',', $val));
                        continue;
                    }
                }
                $normalized[$gpsKey] = $val;
            }
        }

        return $normalized;
    }
}
