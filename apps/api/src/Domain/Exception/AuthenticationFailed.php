<?php

declare(strict_types=1);

namespace Central\Api\Domain\Exception;

/**
 * The caller could not be identified: missing, malformed or expired token.
 *
 * The message is intentionally vague. Telling an attacker whether a token was
 * expired or forged, or whether an email exists, is free reconnaissance.
 */
final class AuthenticationFailed extends DomainException
{
    public function __construct(
        string $message = 'Authentication is required to access this resource.',
        private readonly string $reason = 'invalid_token',
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'unauthenticated';
    }

    /** Logged, never returned to the client. */
    public function reason(): string
    {
        return $this->reason;
    }
}
