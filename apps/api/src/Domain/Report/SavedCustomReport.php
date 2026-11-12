<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * A custom report somebody built and wants back.
 *
 * Stores the canonical config from {@see CustomReportSpec::toConfig()} — the
 * question, never the answer, and never a tenant. Running one re-validates the
 * config against the source as it is now and runs it under the caller's
 * scope.
 *
 * Private to the person who saved it.
 */
final readonly class SavedCustomReport
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $userId,
        public string $name,
        public ?string $description,
        public string $source,
        public array $config,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string> $violations
     */
    public static function fromInput(
        string $tenantId,
        string $userId,
        ?string $id,
        CustomReportSpec $spec,
        array $input,
        array &$violations,
    ): ?self {
        $name = trim((string) (is_scalar($input['name'] ?? null) ? $input['name'] : ''));

        if ($name === '') {
            $violations['name'] = 'Give it a name you will recognise later.';
        } elseif (mb_strlen($name) > 255) {
            $violations['name'] = 'Keep the name under 255 characters.';
        }

        $description = trim((string) (is_scalar($input['description'] ?? null) ? $input['description'] : ''));

        if (mb_strlen($description) > 2000) {
            $violations['description'] = 'Keep the description under 2,000 characters.';
        }

        if ($violations !== []) {
            return null;
        }

        return new self(
            $id,
            $tenantId,
            $userId,
            $name,
            $description === '' ? null : $description,
            $spec->source->key,
            $spec->toConfig(),
        );
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->userId === $userId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'source'      => $this->source,
            'config'      => (object) $this->config,
            'createdAt'   => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
