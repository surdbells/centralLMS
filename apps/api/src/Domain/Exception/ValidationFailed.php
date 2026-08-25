<?php

declare(strict_types=1);

namespace Central\Api\Domain\Exception;

use Symfony\Component\Validator\ConstraintViolationListInterface;

final class ValidationFailed extends DomainException
{
    /**
     * @param array<string, list<string>> $violations field => list of messages
     */
    public function __construct(private readonly array $violations)
    {
        parent::__construct('The submitted data is invalid.');
    }

    public static function fromViolations(ConstraintViolationListInterface $list): self
    {
        $violations = [];

        foreach ($list as $violation) {
            $field = $violation->getPropertyPath() ?: '_';
            $violations[$field][] = (string) $violation->getMessage();
        }

        return new self($violations);
    }

    /**
     * @param array<string, string|list<string>> $violations
     */
    public static function fromArray(array $violations): self
    {
        return new self(array_map(
            static fn (string|array $messages): array => is_array($messages) ? $messages : [$messages],
            $violations,
        ));
    }

    public function errorCode(): string
    {
        return 'validation_failed';
    }

    public function details(): array
    {
        return ['violations' => $this->violations];
    }
}
