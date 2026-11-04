<?php

declare(strict_types=1);

namespace MetaProbe\Security;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Removes privacy-sensitive metadata from images while preserving visual quality.
 *
 * Uses GD for JPEG/PNG/WebP (via imagecopy to guarantee clean output) with fallback
 * to Imagick for TIFF, AVIF, HEIC and when GD is unavailable. Now supports
 * WebP, AVIF and HEIC.
 */
final class Sanitizer
{
    /**
     * Sanitizes an image by stripping PII-containing metadata.
     *
     * If outputPath is the same as inputPath the file is overwritten safely
     * using a temporary file.
     *
     * @param string $inputPath Path to the original image
     * @param string $outputPath Path where sanitized image will be written
     * @return bool True if sanitization succeeded
     * @throws InvalidArgumentException For invalid paths
     * @throws RuntimeException On unrecoverable processing errors
     */
    public function sanitize(string $inputPath, string $outputPath): bool
    {
        $this->validatePaths($inputPath, $outputPath);

        $mimeType = $this->detectMimeType($inputPath);
        $isOverwrite = realpath($inputPath) === realpath($outputPath) || $inputPath === $outputPath;
        $tempPath = $outputPath;

        if ($isOverwrite) {
            $tempPath = $outputPath . '.tmp.' . bin2hex(random_bytes(6));
        }

        $success = match ($mimeType) {
            'image/jpeg' => $this->sanitizeWithGdJpeg($inputPath, $tempPath),
            'image/png' => $this->sanitizeWithGdPng($inputPath, $tempPath),
            'image/webp' => $this->sanitizeWithGdWebp($inputPath, $tempPath),
            'image/tiff', 'image/avif', 'image/heic' => $this->sanitizeWithImagick($inputPath, $tempPath),
            default => false,
        };

        if (!$success) {
            if (isset($tempPath) && $tempPath !== $outputPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
            return false;
        }

        if ($isOverwrite && $tempPath !== $outputPath) {
            if (!@rename($tempPath, $outputPath)) {
                @unlink($tempPath);
                throw new RuntimeException('Failed to replace original file with sanitized version');
            }
        }

        return true;
    }

    /**
     * Validates input/output paths before processing.
     */
    private function validatePaths(string $inputPath, string $outputPath): void
    {
        if (!file_exists($inputPath)) {
            throw new InvalidArgumentException(sprintf('Input file does not exist: %s', $inputPath));
        }

        if (!is_file($inputPath) || !is_readable($inputPath)) {
            throw new InvalidArgumentException(sprintf('Input path is not a readable file: %s', $inputPath));
        }

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir) || !is_writable($outputDir)) {
            throw new InvalidArgumentException(sprintf('Output directory is not writable: %s', $outputDir));
        }
    }

    /**
     * Detects MIME type safely.
     */
    private function detectMimeType(string $path): string
    {
        $mime = mime_content_type($path);

        if ($mime === false) {
            throw new RuntimeException(sprintf('Could not detect MIME type of %s', $path));
        }

        return $mime;
    }

    /**
     * Sanitizes JPEGs using GD + imagecopy to ensure all metadata is stripped.
     */
    private function sanitizeWithGdJpeg(string $inputPath, string $outputPath): bool
    {
        if (!function_exists('imagecreatefromjpeg') || !function_exists('imagejpeg')) {
            return $this->sanitizeWithImagick($inputPath, $outputPath);
        }

        $src = @imagecreatefromjpeg($inputPath);
        if ($src === false) {
            return $this->sanitizeWithImagick($inputPath, $outputPath);
        }

        $width = imagesx($src);
        $height = imagesy($src);

        $dest = imagecreatetruecolor($width, $height);
        if ($dest === false) {
            imagedestroy($src);
            return false;
        }

        // Copy pixels to new canvas — this removes all EXIF, IPTC, XMP
        if (!imagecopy($dest, $src, 0, 0, 0, 0, $width, $height)) {
            imagedestroy($src);
            imagedestroy($dest);
            return false;
        }

        $success = imagejpeg($dest, $outputPath, 92);

        imagedestroy($src);
        imagedestroy($dest);

        return $success;
    }

    /**
     * Sanitizes PNGs using GD (strips text chunks).
     */
    private function sanitizeWithGdPng(string $inputPath, string $outputPath): bool
    {
        if (!function_exists('imagecreatefrompng') || !function_exists('imagepng')) {
            return $this->sanitizeWithImagick($inputPath, $outputPath);
        }

        $src = @imagecreatefrompng($inputPath);
        if ($src === false) {
            return false;
        }

        $width = imagesx($src);
        $height = imagesy($src);

        $dest = imagecreatetruecolor($width, $height);
        if ($dest === false) {
            imagedestroy($src);
            return false;
        }

        imagecopy($dest, $src, 0, 0, 0, 0, $width, $height);
        $success = imagepng($dest, $outputPath, 6); // balanced compression

        imagedestroy($src);
        imagedestroy($dest);

        return $success;
    }

    /**
     * Sanitizes WebP files using GD (strips all metadata).
     */
    private function sanitizeWithGdWebp(string $inputPath, string $outputPath): bool
    {
        if (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) {
            return $this->sanitizeWithImagick($inputPath, $outputPath);
        }

        $src = @imagecreatefromwebp($inputPath);
        if ($src === false) {
            return $this->sanitizeWithImagick($inputPath, $outputPath);
        }

        $width = imagesx($src);
        $height = imagesy($src);

        $dest = imagecreatetruecolor($width, $height);
        if ($dest === false) {
            imagedestroy($src);
            return false;
        }

        imagecopy($dest, $src, 0, 0, 0, 0, $width, $height);
        $success = imagewebp($dest, $outputPath, 85);

        imagedestroy($src);
        imagedestroy($dest);

        return $success;
    }

    /**
     * Fallback using Imagick (strips all profiles/comments).
     *
     * Preserves visual quality at JPEG/WebP quality 92. Supports TIFF, AVIF
     * and HEIC when the underlying Imagick delegates are available.
     */
    private function sanitizeWithImagick(string $inputPath, string $outputPath): bool
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }

        try {
            $imagick = new \Imagick($inputPath);

            // Remove all metadata
            $imagick->stripImage();

            $mime = $this->detectMimeType($inputPath);
            $format = match ($mime) {
                'image/jpeg' => 'jpeg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/avif' => 'avif',
                'image/heic' => 'heic',
                'image/tiff' => 'tiff',
                default => 'jpeg',
            };

            $imagick->setImageFormat($format);

            if (in_array($format, ['jpeg', 'webp'], true)) {
                $imagick->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $imagick->setImageCompressionQuality(92);
            } elseif ($format === 'png') {
                $imagick->setOption('png:compression-level', '6');
            } elseif ($format === 'avif') {
                $imagick->setOption('avif:compression', 'av1');
                $imagick->setImageCompressionQuality(85);
            } elseif ($format === 'heic') {
                $imagick->setOption('heic:compression', 'hevc');
                $imagick->setImageCompressionQuality(85);
            }

            $result = $imagick->writeImage($outputPath);
            $imagick->clear();
            $imagick->destroy();

            return $result;
        } catch (Throwable $e) {
            return false;
        }
    }
}
