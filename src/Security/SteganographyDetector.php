<?php

declare(strict_types=1);

namespace MetaProbe\Security;

use GdImage;
use RuntimeException;

/**
 * Detects potential steganography by analyzing LSB (Least Significant Bit)
 * patterns in the RGB pixel data of an image.
 *
 * Uses GD to sample pixels (for performance) and applies two statistical
 * heuristics:
 *   - proportion of 1-bits suspiciously close to 50%
 *   - transition rate between adjacent bits close to 50% (randomness)
 *
 * These are classic indicators of LSB replacement hiding.
 */
final class SteganographyDetector
{
    private const SAMPLE_SIZE = 8000;
    private const PROPORTION_TOLERANCE = 0.028;
    private const TRANSITION_TOLERANCE = 0.055;

    /**
     * Analyzes the image and returns detected risks plus a score penalty.
     *
     * @return array{risks: list<string>, scorePenalty: int}
     */
    public function analyze(string $path): array
    {
        if (!function_exists('imagecreatefromstring')) {
            return [
                'risks' => [],
                'scorePenalty' => 0,
            ];
        }

        $image = $this->loadImage($path);
        if ($image === null) {
            return [
                'risks' => [],
                'scorePenalty' => 0,
            ];
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            $lsbBits = $this->extractLsbSample($image, $width, $height);

            $risks = $this->detectAnomalies($lsbBits);
            $penalty = count($risks) * 30;

            return [
                'risks' => $risks,
                'scorePenalty' => min(65, $penalty),
            ];
        } finally {
            if (isset($image) && $image instanceof GdImage) {
                imagedestroy($image);
            }
        }
    }

    private function loadImage(string $path): ?GdImage
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $image = @imagecreatefromstring($content);
        if ($image === false) {
            return null;
        }

        return $image;
    }

    /**
     * Extracts LSB (0 or 1) for R, G and B of a representative sample of pixels.
     *
     * @return list<int>
     */
    private function extractLsbSample(GdImage $image, int $width, int $height): array
    {
        $bits = [];
        $maxSamples = self::SAMPLE_SIZE;
        $totalPixels = $width * $height;
        $step = max(1, (int) ($totalPixels / $maxSamples));

        $count = 0;
        for ($y = 0; $y < $height && $count < $maxSamples; $y += $step) {
            for ($x = 0; $x < $width && $count < $maxSamples; $x += $step) {
                $rgb = @imagecolorat($image, $x, $y);
                if ($rgb === false) {
                    continue;
                }

                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $bits[] = $r & 1;
                $bits[] = $g & 1;
                $bits[] = $b & 1;

                $count++;
            }
        }

        return $bits;
    }

    /**
     * Detects statistical anomalies that suggest hidden data.
     *
     * @param list<int> $bits
     * @return list<string>
     */
    private function detectAnomalies(array $bits): array
    {
        $n = count($bits);
        if ($n < 500) {
            return [];
        }

        $ones = count(array_filter($bits, static fn (int $b): bool => $b === 1));
        $proportion = $ones / $n;

        $risks = [];

        if (abs($proportion - 0.5) < self::PROPORTION_TOLERANCE) {
            $risks[] = 'Unusually balanced LSB distribution (possible LSB steganography)';
        }

        $transitions = $this->countTransitions($bits);
        $transitionRate = $n > 1 ? $transitions / ($n - 1) : 0.0;

        if (abs($transitionRate - 0.5) < self::TRANSITION_TOLERANCE) {
            $risks[] = 'High randomness in LSB plane suggests hidden data';
        }

        return $risks;
    }

    private function countTransitions(array $bits): int
    {
        $transitions = 0;
        $n = count($bits);

        for ($i = 1; $i < $n; $i++) {
            if ($bits[$i] !== $bits[$i - 1]) {
                $transitions++;
            }
        }

        return $transitions;
    }
}
