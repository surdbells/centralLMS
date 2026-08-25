<?php

declare(strict_types=1);

namespace Central\Api\Domain\Exception;

/**
 * The request is well-formed but cannot be applied to the current state —
 * double enrolment, a session already cancelled, a duplicate slug.
 */
final class ConflictingState extends DomainException
{
    public function __construct(string $message, private readonly string $errorCode = 'conflict')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
