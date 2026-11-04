<?php

declare(strict_types=1);

namespace MetaProbe;

/**
 * Common interface for all metadata readers.
 *
 * Allows the core to support multiple formats (EXIF, IPTC, XMP) and makes
 * the library easily extensible for new image types or metadata standards.
 */
interface ReaderInterface
{
    /**
     * Determines if this reader can handle images of the given MIME type.
     *
     * @param string $mimeType The detected MIME type (e.g. image/jpeg)
     * @return bool True if the reader supports this format
     */
    public function supports(string $mimeType): bool;

    /**
     * Reads and normalizes metadata from the image file.
     *
     * @param string $path Path to the image file
     * @return array<string, mixed> Normalized key/value metadata
     */
    public function read(string $path): array;
}
