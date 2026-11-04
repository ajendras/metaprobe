<?php

declare(strict_types=1);

namespace MetaProbe\Readers;

use MetaProbe\ReaderInterface;
use Throwable;

/**
 * Reads IPTC-IIM metadata using getimagesize() + iptcparse().
 *
 * Converts numeric dataset identifiers (e.g. 2#080) into readable keys
 * and normalizes multi-value fields (keywords, categories) into arrays.
 */
final class IptcReader implements ReaderInterface
{
    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/tiff'], true);
    }

    /**
     * Reads IPTC data embedded in the APP13 segment.
     *
     * @param string $path Path to the image file
     * @return array<string, mixed> Normalized IPTC fields
     */
    public function read(string $path): array
    {
        try {
            $info = [];
            $size = getimagesize($path, $info);

            if ($size === false || !isset($info['APP13'])) {
                return [];
            }

            $iptcRaw = iptcparse($info['APP13']);

            if ($iptcRaw === false) {
                return [];
            }

            return $this->normalize($iptcRaw);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Maps IPTC dataset numbers to human-readable keys and normalizes values.
     *
     * @param array<string, array<int, string>> $raw Raw output from iptcparse()
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        $mapping = [
            '2#005' => 'title',
            '2#010' => 'urgency',
            '2#015' => 'category',
            '2#020' => 'subcategories',
            '2#025' => 'keywords',
            '2#040' => 'specialInstructions',
            '2#055' => 'dateCreated',
            '2#060' => 'timeCreated',
            '2#080' => 'author',
            '2#085' => 'authorTitle',
            '2#090' => 'city',
            '2#095' => 'state',
            '2#100' => 'location',
            '2#101' => 'country',
            '2#105' => 'headline',
            '2#110' => 'credit',
            '2#115' => 'source',
            '2#116' => 'copyright',
            '2#120' => 'caption',
            '2#122' => 'captionWriter',
        ];

        $normalized = [];

        foreach ($mapping as $code => $key) {
            if (!isset($raw[$code])) {
                continue;
            }

            $value = $raw[$code];

            if (is_array($value)) {
                if (count($value) === 1 && !in_array($key, ['keywords', 'subcategories'], true)) {
                    $normalized[$key] = $value[0];
                } else {
                    $normalized[$key] = $value;
                }
            } else {
                $normalized[$key] = $value;
            }
        }

        // Ensure keywords is always an array
        if (isset($normalized['keywords']) && !is_array($normalized['keywords'])) {
            $normalized['keywords'] = [$normalized['keywords']];
        }

        // Ensure subcategories is always an array
        if (isset($normalized['subcategories']) && !is_array($normalized['subcategories'])) {
            $normalized['subcategories'] = [$normalized['subcategories']];
        }

        return $normalized;
    }
}
