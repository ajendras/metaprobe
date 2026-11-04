<?php

declare(strict_types=1);

namespace MetaProbe\Readers;

use MetaProbe\ReaderInterface;
use RuntimeException;
use Throwable;

/**
 * Reads and normalizes EXIF metadata using PHP's native exif_read_data().
 *
 * Extracts data from IFD0 (camera/make/model), EXIF (exposure/lens/settings)
 * and GPS blocks. Provides additional friendly keys (Camera, Lens, Exposure)
 * while preserving original tag values for downstream consumers such as Gps.
 */
final class ExifReader implements ReaderInterface
{
    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/tiff'], true);
    }

    /**
     * Reads EXIF data and returns a normalized associative array.
     *
     * @param string $path Path to the image file
     * @return array<string, mixed> Normalized metadata (standard EXIF keys + computed fields)
     */
    public function read(string $path): array
    {
        if (!function_exists('exif_read_data')) {
            return [];
        }

        try {
            // Read with sections=true to obtain structured blocks (GPS is kept as array of rationals)
            $raw = @exif_read_data($path, 'ANY_TAG', true);

            if ($raw === false) {
                return [];
            }

            return $this->normalize($raw);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Flattens sectioned EXIF data and adds computed friendly fields.
     *
     * @param array<string, mixed> $raw Raw output from exif_read_data(..., true)
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        $normalized = [];

        $sections = ['FILE', 'COMPUTED', 'IFD0', 'EXIF', 'GPS', 'INTEROP', 'THUMBNAIL'];

        foreach ($sections as $section) {
            if (isset($raw[$section]) && is_array($raw[$section])) {
                foreach ($raw[$section] as $key => $value) {
                    // Keep original key names (e.g. GPSLatitude, ExposureTime, Make)
                    $normalized[$key] = $value;
                }
            }
        }

        // Friendly computed fields
        if (!empty($normalized['Make']) || !empty($normalized['Model'])) {
            $make = trim((string) ($normalized['Make'] ?? ''));
            $model = trim((string) ($normalized['Model'] ?? ''));
            $normalized['Camera'] = $make !== '' && $model !== ''
                ? $make . ' ' . $model
                : ($make ?: $model);
        }

        // Lens information (EXIF 2.3+)
        if (!empty($normalized['LensModel'])) {
            $normalized['Lens'] = $normalized['LensModel'];
        } elseif (isset($raw['EXIF']['UndefinedTag:0xA434'])) {
            $normalized['Lens'] = $raw['EXIF']['UndefinedTag:0xA434'];
        }

        // Exposure summary string
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

        // Normalize common date fields
        if (isset($normalized['DateTimeOriginal'])) {
            $normalized['DateTime'] = $normalized['DateTimeOriginal'];
        } elseif (isset($normalized['DateTime'])) {
            $normalized['DateTimeOriginal'] = $normalized['DateTime'];
        }

        // Ensure GPS fields remain in the exact format expected by Gps::fromExif()
        foreach (['GPSLatitude', 'GPSLongitude', 'GPSLatitudeRef', 'GPSLongitudeRef'] as $gpsKey) {
            if (isset($normalized[$gpsKey]) && is_array($normalized[$gpsKey])) {
                // Arrays of rationals are left untouched for Gps parser
            }
        }

        return $normalized;
    }
}
