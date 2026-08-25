<?php

declare(strict_types=1);

namespace Central\Api\Domain\Exception;

final class EntityNotFound extends DomainException
{
    public function __construct(
        private readonly string $entity,
        private readonly string $identifier,
    ) {
        parent::__construct(sprintf('%s "%s" was not found.', $entity, $identifier));
    }

    public function errorCode(): string
    {
        return 'not_found';
    }

    public function details(): array
    {
        return ['entity' => $this->entity, 'id' => $this->identifier];
    }
}
