<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * What happened when a transport tried to send.
 *
 * `retryable` is the field that matters. A network timeout deserves another
 * attempt; an address the provider has rejected as malformed does not, and
 * retrying it three times only delays the moment someone notices it is wrong.
 */
final readonly class TransportResult
{
    private function __construct(
        public bool $sent,
        public ?string $providerMessageId,
        public ?string $error,
        public bool $retryable,
    ) {
    }

    public static function sent(?string $providerMessageId = null): self
    {
        return new self(true, $providerMessageId, null, false);
    }

    /** A transient problem: try again later. */
    public static function failed(string $error): self
    {
        return new self(false, null, $error, true);
    }

    /** Permanently undeliverable: do not try again. */
    public static function rejected(string $error): self
    {
        return new self(false, null, $error, false);
    }
}
