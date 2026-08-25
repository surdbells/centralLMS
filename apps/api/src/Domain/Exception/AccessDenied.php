<?php

declare(strict_types=1);

namespace Central\Api\Domain\Exception;

final class AccessDenied extends DomainException
{
    public function __construct(private readonly ?string $permission = null)
    {
        parent::__construct('You do not have permission to perform this action.');
    }

    public function errorCode(): string
    {
        return 'forbidden';
    }

    public function details(): array
    {
        return $this->permission === null ? [] : ['requiredPermission' => $this->permission];
    }
}
