<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt\Exception;

use Central\Api\Domain\Exception\DomainException;
use Central\Api\Domain\Ilt\MeetingProviderId;

/**
 * A conferencing vendor refused or failed a request.
 *
 * Carries `retryable` so the queue worker can tell a transient 503 from a
 * permanent "your plan does not allow this" — retrying the latter forever is
 * how the legacy integration filled its job table.
 */
final class MeetingProviderError extends DomainException
{
    public function __construct(
        private readonly MeetingProviderId $provider,
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(MeetingProviderId $provider): self
    {
        return new self(
            $provider,
            sprintf(
                '%s is not configured. Set its credentials and enable it before scheduling sessions.',
                $provider->label(),
            ),
        );
    }

    /**
     * The tenant has no usable connection: never connected, disabled, or the
     * refresh token was revoked. Recoverable only by an admin reconnecting.
     */
    public static function notConnected(MeetingProviderId $provider): self
    {
        return new self(
            $provider,
            sprintf(
                '%s is not connected for this organisation. An administrator needs to connect it '
                . 'in Settings before virtual sessions can be scheduled.',
                $provider->label(),
            ),
            null,
            false,
        );
    }

    public static function fromHttp(MeetingProviderId $provider, int $status, string $body): self
    {
        // 408/429 and 5xx are worth another attempt; 4xx means we sent
        // something the vendor will reject again no matter how often we try.
        $retryable = $status === 408 || $status === 429 || $status >= 500;

        return new self(
            $provider,
            sprintf('%s API returned HTTP %d: %s', $provider->label(), $status, self::truncate($body)),
            $status,
            $retryable,
        );
    }

    public function errorCode(): string
    {
        return 'meeting_provider_error';
    }

    public function details(): array
    {
        return array_filter([
            'provider'   => $this->provider->value,
            'httpStatus' => $this->httpStatus,
            'retryable'  => $this->retryable,
        ], static fn (mixed $v): bool => $v !== null);
    }

    public function provider(): MeetingProviderId
    {
        return $this->provider;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    private static function truncate(string $body, int $max = 500): string
    {
        $body = trim($body);

        return strlen($body) <= $max ? $body : substr($body, 0, $max) . '…';
    }
}
