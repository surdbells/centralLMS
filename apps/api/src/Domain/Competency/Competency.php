<?php

declare(strict_types=1);

namespace Central\Api\Domain\Competency;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * One competency in the dictionary: a named capability, in a function, with
 * what each level of it looks like.
 */
final readonly class Competency
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $functionName,
        public string $name,
        public ?string $description = null,
        public ?string $knowledgeDescriptor = null,
        public ?string $skillDescriptor = null,
        public ?string $masteryDescriptor = null,
        public ?\DateTimeImmutable $createdAt = null,
        /** Counted for the dictionary listing, never stored. */
        public int $profileCount = 0,
        public int $assessedCount = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(string $id, string $tenantId, array $input): self
    {
        $name = trim((string) ($input['name'] ?? ''));
        $function = trim((string) ($input['functionName'] ?? $input['function'] ?? ''));

        $violations = [];

        if ($name === '') {
            $violations['name'] = 'A competency needs a name.';
        }

        if ($function === '') {
            $violations['functionName'] = 'Say which function it belongs to.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            functionName: $function,
            name: $name,
            description: self::text($input['description'] ?? null),
            knowledgeDescriptor: self::text($input['knowledgeDescriptor'] ?? null),
            skillDescriptor: self::text($input['skillDescriptor'] ?? null),
            masteryDescriptor: self::text($input['masteryDescriptor'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'functionName' => $this->functionName,
            'name'         => $this->name,
            'description'  => $this->description,
            'levels'       => [
                'knowledge' => $this->knowledgeDescriptor,
                'skill'     => $this->skillDescriptor,
                'mastery'   => $this->masteryDescriptor,
            ],
            'profileCount'  => $this->profileCount,
            'assessedCount' => $this->assessedCount,
            'createdAt'     => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
