<?php

declare(strict_types=1);

namespace Central\Api\Application\Library;

/**
 * Convert one library document to PDF, off the request.
 *
 * Dispatched when an Office document is uploaded to the library. The worker
 * runs LibreOffice, which is slow and must not hold up the upload response, and
 * writes the result back. The message carries only ids — the worker reads the
 * current row, so a document deleted before the worker reaches it is a no-op
 * rather than a conversion of bytes nothing points at.
 */
final readonly class ConvertDocumentToPdf
{
    public function __construct(
        public string $tenantId,
        public string $itemId,
    ) {
    }
}
