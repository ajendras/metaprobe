<?php

declare(strict_types=1);

namespace MetaProbe\Middleware;

use MetaProbe\MetaProbe;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

/**
 * PSR-15 middleware that auto-sanitizes uploaded images.
 *
 * Inspects all uploaded files (including nested arrays for multiple fields)
 * in a PSR-7 ServerRequestInterface. Any file whose client media type
 * indicates an image is passed through MetaProbe::sanitize() on its
 * temporary disk path. This guarantees that GPS, camera fingerprints,
 * author data and other PII never reach your application code or storage.
 *
 * Works with any PSR-15 compatible framework (Slim, Laminas Mezzio,
 * Symfony, etc). The middleware is zero-config but can be told to throw
 * on sanitization failure.
 */
final class ImageSanitizationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly bool $throwOnFailure = false
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->sanitizeUploads($request);

        return $handler->handle($request);
    }

    /**
     * Walks the uploaded files tree and sanitizes every image.
     */
    private function sanitizeUploads(ServerRequestInterface $request): void
    {
        $this->walkUploadedFiles($request->getUploadedFiles());
    }

    /**
     * Recursively processes uploaded files (handles both single files and
     * nested arrays from <input name="photos[]"> fields).
     *
     * @param array<string,mixed>|UploadedFileInterface $files
     */
    private function walkUploadedFiles(mixed $files): void
    {
        if ($files instanceof UploadedFileInterface) {
            if ($this->isImageUpload($files)) {
                $this->sanitizeUploadedFile($files);
            }
            return;
        }

        if (is_array($files)) {
            foreach ($files as $file) {
                $this->walkUploadedFiles($file);
            }
        }
    }

    private function isImageUpload(UploadedFileInterface $file): bool
    {
        $mediaType = $file->getClientMediaType();

        if ($mediaType === null || $mediaType === '') {
            return false;
        }

        return str_starts_with(strtolower($mediaType), 'image/');
    }

    private function sanitizeUploadedFile(UploadedFileInterface $file): void
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return;
        }

        $tmpPath = $this->getTemporaryFilePath($file);

        if ($tmpPath === null || !is_file($tmpPath) || !is_writable($tmpPath)) {
            return;
        }

        try {
            // Sanitize in-place. Sanitizer internally uses a safe temp-file
            // swap when input and output paths are identical.
            MetaProbe::sanitize($tmpPath);
        } catch (Throwable $e) {
            if ($this->throwOnFailure) {
                throw new RuntimeException(
                    sprintf(
                        'Failed to sanitize uploaded image "%s": %s',
                        $file->getClientFilename() ?? 'unknown',
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }
            // Silent continuation is the default so a single bad upload
            // does not break the entire request.
        }
    }

    /**
     * Returns the on-disk temporary path for the uploaded file.
     *
     * Most PSR-7 implementations expose this via $stream->getMetadata('uri').
     * Returns null if the stream is detached or the path is unavailable.
     */
    private function getTemporaryFilePath(UploadedFileInterface $file): ?string
    {
        try {
            $stream = $file->getStream();
            /** @var array<string,mixed> $metadata */
            $metadata = $stream->getMetadata();

            if (isset($metadata['uri']) && is_string($metadata['uri']) && file_exists($metadata['uri'])) {
                return $metadata['uri'];
            }
        } catch (Throwable $e) {
            // Stream may be detached, closed, or not support metadata.
        }

        return null;
    }
}
