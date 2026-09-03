<?php

declare(strict_types=1);

namespace Central\Api\Domain\Library;

/**
 * Where a library item stands in becoming viewable inline.
 *
 * A file a browser already renders — a video, a PDF, an image — needs no
 * conversion, so it is `NotRequired` and viewable at once. An Office document
 * is not something a browser shows inline, so it is `Pending` on upload,
 * `Processing` while the worker runs LibreOffice, and `Done` once the PDF it
 * produced is stored — or `Failed` if the conversion could not be run.
 */
enum ConversionStatus: string
{
    case NotRequired = 'not_required';
    case Pending     = 'pending';
    case Processing  = 'processing';
    case Done        = 'done';
    case Failed      = 'failed';

    /** Whether the item can be viewed inline right now. */
    public function isViewable(): bool
    {
        return $this === self::NotRequired || $this === self::Done;
    }

    /** Whether the worker still has work to do on it. */
    public function isPending(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }

    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::NotRequired;
    }
}
