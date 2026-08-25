<?php

declare(strict_types=1);

namespace Central\Api\Domain\Exception;

/**
 * Base for every expected failure the domain can signal.
 *
 * Domain code stays free of HTTP concepts — the mapping from these classes to
 * status codes lives in {@see \Central\Api\Application\Handler\JsonErrorHandler}.
 * Anything that is *not* a DomainException is treated as a bug: logged at
 * error level, reported to Sentry, and returned as a bare 500.
 */
abstract class DomainException extends \RuntimeException
{
    /**
     * Stable, machine-readable identifier for API clients to branch on.
     * Never change one of these without versioning the API.
     */
    abstract public function errorCode(): string;

    /**
     * Extra structured context returned to the client under `error.details`.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [];
    }
}
