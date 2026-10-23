<?php

declare(strict_types=1);

namespace Central\Api\Application\Media;

/**
 * Unpack one SCORM package, off the request.
 *
 * Dispatched when a SCORM `.zip` is uploaded to the media library. Unzipping a
 * package (up to 1 GB) and writing out its files is slow and must not hold up
 * the upload response, so the worker does it. The message carries only ids —
 * the worker reads the current row, so a file deleted before the worker reaches
 * it is a no-op rather than an extraction of bytes nothing points at.
 */
final readonly class ExtractScormPackage
{
    public function __construct(
        public string $tenantId,
        public string $mediaId,
    ) {
    }
}
