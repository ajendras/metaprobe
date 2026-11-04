<?php

declare(strict_types=1);

namespace MetaProbe\Security;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Removes privacy-sensitive metadata from images while preserving visual quality.
 *
 * Uses GD for JPEG/PNG (via imagecopy to guarantee clean output) with fallback
 * to Imagick when available for broader format support and finer control.
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
            'image/tiff' => $this->sanitizeWithImagick($inputPath, $tempPath),
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
     * Fallback using Imagick (strips all profiles/comments).
     *
     * Preserves visual quality at JPEG quality 92. If Imagick is not available
     * the method returns false.
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

            $mime = mime_content_type($inputPath);
            if ($mime === 'image/jpeg') {
                $imagick->setImageFormat('jpeg');
                $imagick->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $imagick->setImageCompressionQuality(92);
            } elseif ($mime === 'image/png') {
                $imagick->setImageFormat('png');
                $imagick->setOption('png:compression-level', '6');
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
