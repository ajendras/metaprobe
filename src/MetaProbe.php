<?php

declare(strict_types=1);

namespace MetaProbe;

use InvalidArgumentException;
use MetaProbe\Gps;
use MetaProbe\Metadata;
use MetaProbe\Readers\ExifReader;
use MetaProbe\Readers\ImagickReader;
use MetaProbe\Readers\IptcReader;
use MetaProbe\Readers\ReaderInterface;
use MetaProbe\Readers\XmpReader;
use MetaProbe\Security\Sanitizer;
use MetaProbe\Security\Scanner;
use MetaProbe\Security\SecurityReport;
use RuntimeException;
use Throwable;

/**
 * Main facade for the MetaProbe library.
 *
 * Provides a clean, unified API for metadata extraction, privacy scanning,
 * sanitization and batch directory processing of JPEG, PNG, TIFF, WebP, AVIF and HEIC images.
 */
final class MetaProbe
{
    /**
     * @var ReaderInterface[]
     */
    private array $readers;

    private Scanner $scanner;
    private Sanitizer $sanitizer;

    public function __construct()
    {
        $this->readers = [
            new ExifReader(),
            new IptcReader(),
            new XmpReader(),
            new ImagickReader(),
        ];

        $this->scanner = new Scanner();
        $this->sanitizer = new Sanitizer();
    }

    /**
     * Probe an image and return a rich Metadata DTO.
     *
     * @param string $path Absolute or relative path to an image file
     * @return Metadata Structured metadata container
     * @throws InvalidArgumentException When the file is missing, unreadable or unsupported
     */
    public static function probe(string $path): Metadata
    {
        return (new self())->doProbe($path);
    }

    /**
     * Scan an image for privacy and security risks.
     *
     * Returns a numeric safety score (0-100) and list of detected risks.
     *
     * @param string $path Absolute or relative path to an image file
     * @return SecurityReport Detailed security analysis
     * @throws InvalidArgumentException When the file cannot be processed
     */
    public static function scan(string $path): SecurityReport
    {
        return (new self())->doScan($path);
    }

    /**
     * Sanitize an image by stripping sensitive metadata while preserving
     * color profiles, copyright notices and essential structural data.
     *
     * If $outputPath is omitted the original file is overwritten (use with caution).
     *
     * @param string $inputPath Path to source image
     * @param string|null $outputPath Where to write the sanitized image (null = overwrite)
     * @return bool True on success
     * @throws InvalidArgumentException When input is invalid
     * @throws RuntimeException When sanitization fails
     */
    public static function sanitize(string $inputPath, ?string $outputPath = null): bool
    {
        return (new self())->doSanitize($inputPath, $outputPath);
    }

    /**
     * Returns all supported image files inside a directory.
     *
     * @param string $directory Directory to scan
     * @param bool $recursive Whether to traverse subdirectories
     * @return list<string> Absolute paths to supported image files, sorted
     * @throws InvalidArgumentException When directory is invalid
     */
    public static function findImages(string $directory, bool $recursive = false): array
    {
        return (new self())->doFindImages($directory, $recursive);
    }

    /**
     * Batch-process every supported image in a directory.
     *
     * Returns a structured summary row for each file (metadata + privacy score).
     * The optional $progressCallback is used by the CLI to drive a ProgressBar.
     *
     * @param string $directory Directory containing images
     * @param bool $recursive Whether to scan subdirectories
     * @param callable|null $progressCallback Signature: fn(int $processed, int $total, string $file): void
     * @return array<int, array<string, mixed>> Summary rows
     * @throws InvalidArgumentException When directory is invalid
     */
    public static function batch(string $directory, bool $recursive = false, ?callable $progressCallback = null): array
    {
        return (new self())->doBatch($directory, $recursive, $progressCallback);
    }

    /**
     * Instance implementation of probe().
     */
    private function doProbe(string $path): Metadata
    {
        $this->validateImage($path);
        $mimeType = $this->detectMimeType($path);

        if (!$this->isSupportedMime($mimeType)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported MIME type "%s". Supported types: image/jpeg, image/png, image/tiff, image/webp, image/avif, image/heic.',
                $mimeType
            ));
        }

        $exifData = [];
        $iptcData = [];
        $xmpData = [];

        foreach ($this->readers as $reader) {
            if (!$reader->supports($mimeType)) {
                continue;
            }

            try {
                $data = $reader->read($path);

                if ($reader instanceof ExifReader) {
                    $exifData = $data;
                } elseif ($reader instanceof IptcReader) {
                    $iptcData = $data;
                } elseif ($reader instanceof XmpReader) {
                    $xmpData = $data;
                } elseif ($reader instanceof ImagickReader) {
                    $exifData = array_merge($exifData, $data);
                }
            } catch (Throwable $e) {
                // Continue with partial data; errors are surfaced in SecurityReport
            }
        }

        $gps = null;
        if (!empty($exifData['GPSLatitude']) && !empty($exifData['GPSLongitude'])) {
            $gps = Gps::fromExif($exifData);
        } elseif (isset($exifData['GPS']) && is_array($exifData['GPS'])) {
            $gps = Gps::fromExif($exifData['GPS']);
        }

        return new Metadata(
            exif: $exifData,
            iptc: $iptcData,
            xmp: $xmpData,
            gps: $gps
        );
    }

    /**
     * Instance implementation of scan().
     */
    private function doScan(string $path): SecurityReport
    {
        $metadata = $this->doProbe($path);

        $risks = $this->scanner->getRisks($metadata);
        $score = $this->scanner->calculateScore($metadata);

        return new SecurityReport(
            score: $score,
            risks: $risks,
            metadata: $metadata
        );
    }

    /**
     * Instance implementation of sanitize().
     */
    private function doSanitize(string $inputPath, ?string $outputPath = null): bool
    {
        $this->validateImage($inputPath);

        if ($outputPath === null) {
            $outputPath = $inputPath;
        }

        if (!is_writable(dirname($outputPath))) {
            throw new InvalidArgumentException(sprintf(
                'Output directory is not writable: %s',
                dirname($outputPath)
            ));
        }

        return $this->sanitizer->sanitize($inputPath, $outputPath);
    }

    /**
     * Instance implementation of findImages().
     */
    private function doFindImages(string $directory, bool $recursive = false): array
    {
        $this->validateDirectory($directory);

        $supportedExts = ['jpg', 'jpeg', 'png', 'tiff', 'tif', 'webp', 'avif', 'heic'];
        $files = [];

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $directory,
                    \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
                )
            );

            /** @var \SplFileInfo $fileInfo */
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile()) {
                    $ext = strtolower($fileInfo->getExtension());
                    if (in_array($ext, $supportedExts, true)) {
                        $files[] = $fileInfo->getPathname();
                    }
                }
            }
        } else {
            $items = scandir($directory);
            if ($items === false) {
                return [];
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $path = $directory . DIRECTORY_SEPARATOR . $item;
                if (is_file($path)) {
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    if (in_array($ext, $supportedExts, true)) {
                        $files[] = $path;
                    }
                }
            }
        }

        sort($files);
        return $files;
    }

    /**
     * Instance implementation of batch().
     */
    private function doBatch(string $directory, bool $recursive = false, ?callable $progressCallback = null): array
    {
        $files = $this->doFindImages($directory, $recursive);
        $summary = [];
        $total = count($files);

        foreach ($files as $index => $filePath) {
            if ($progressCallback !== null) {
                $progressCallback($index + 1, $total, $filePath);
            }

            try {
                $metadata = $this->doProbe($filePath);
                $risks = $this->scanner->getRisks($metadata);
                $score = $this->scanner->calculateScore($metadata);
                $exif = $metadata->getExif();
                $gps = $metadata->getGps();

                $summary[] = [
                    'filename' => basename($filePath),
                    'path' => $filePath,
                    'mime_type' => $this->detectMimeType($filePath),
                    'size_kb' => round(filesize($filePath) / 1024, 2),
                    'privacy_score' => $score,
                    'risks_count' => count($risks),
                    'has_gps' => $gps !== null ? 'YES' : 'NO',
                    'gps_coordinates' => $gps !== null ? (string) $gps : '',
                    'camera' => $exif['Camera'] ?? ($exif['Model'] ?? 'N/A'),
                    'date_taken' => $exif['DateTimeOriginal'] ?? ($exif['DateTime'] ?? 'N/A'),
                    'risk_details' => $risks,
                ];
            } catch (Throwable $e) {
                $summary[] = [
                    'filename' => basename($filePath),
                    'path' => $filePath,
                    'error' => $e->getMessage(),
                    'privacy_score' => 0,
                    'risks_count' => 0,
                    'has_gps' => 'NO',
                    'gps_coordinates' => '',
                    'camera' => 'N/A',
                    'date_taken' => 'N/A',
                    'risk_details' => [],
                ];
            }
        }

        return $summary;
    }

    /**
     * Validates that a file exists, is readable and is a regular file.
     */
    private function validateImage(string $path): void
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException(sprintf('File does not exist: %s', $path));
        }

        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Path is not a regular file: %s', $path));
        }

        if (!is_readable($path)) {
            throw new InvalidArgumentException(sprintf('File is not readable: %s', $path));
        }
    }

    /**
     * Validates a directory for batch processing.
     */
    private function validateDirectory(string $directory): void
    {
        if (!file_exists($directory)) {
            throw new InvalidArgumentException(sprintf('Directory does not exist: %s', $directory));
        }

        if (!is_dir($directory)) {
            throw new InvalidArgumentException(sprintf('Path is not a directory: %s', $directory));
        }

        if (!is_readable($directory)) {
            throw new InvalidArgumentException(sprintf('Directory is not readable: %s', $directory));
        }
    }

    /**
     * Detect MIME type using PHP's built-in function.
     */
    private function detectMimeType(string $path): string
    {
        $mime = mime_content_type($path);

        if ($mime === false) {
            throw new RuntimeException(sprintf('Failed to detect MIME type for %s', $path));
        }

        return $mime;
    }

    /**
     * Returns whether the given MIME type can be processed by this library.
     */
    private function isSupportedMime(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/tiff', 'image/webp', 'image/avif', 'image/heic'], true);
    }

    /**
     * Returns the list of registered readers (useful for debugging or extension).
     *
     * @return ReaderInterface[]
     */
    public function getReaders(): array
    {
        return $this->readers;
    }
}
