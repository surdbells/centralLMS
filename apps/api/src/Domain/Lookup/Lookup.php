<?php

declare(strict_types=1);

namespace Central\Api\Domain\Lookup;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * One piece of tenant reference data — a branch, department, team, level, job
 * title or category.
 */
final readonly class Lookup
{
    /**
     * @param array<string, mixed> $attributes kind-specific extras, see LookupKind::allowedAttributes()
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public LookupKind $kind,
        public string $name,
        public ?string $code = null,
        public ?string $description = null,
        public ?string $parentId = null,
        public int $sortOrder = 0,
        public bool $isActive = true,
        public array $attributes = [],
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Builds a validated instance from client input.
     *
     * Validation lives here rather than in the action so that every entry
     * point — HTTP, the data migration, a future import — gets the same rules.
     * The legacy endpoints validated inconsistently, in 36 places.
     *
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(
        string $id,
        string $tenantId,
        LookupKind $kind,
        array $input,
    ): self {
        $violations = [];

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            $violations['name'] = sprintf('Enter a %s name.', strtolower($kind->label()));
        } elseif (mb_strlen($name) > 150) {
            $violations['name'] = 'Name must be 150 characters or fewer.';
        }

        $code = isset($input['code']) ? trim((string) $input['code']) : null;

        if ($code === '') {
            $code = null;
        }

        if ($code !== null) {
            if (!$kind->usesCode()) {
                $violations['code'] = sprintf('A %s does not have a code.', strtolower($kind->label()));
            } elseif (mb_strlen($code) > 30) {
                $violations['code'] = 'Code must be 30 characters or fewer.';
            }
        }

        $parentId = isset($input['parentId']) && trim((string) $input['parentId']) !== ''
            ? trim((string) $input['parentId'])
            : null;

        if ($parentId !== null && !$kind->supportsHierarchy()) {
            $violations['parentId'] = sprintf('A %s cannot have a parent.', strtolower($kind->label()));
        }

        if ($parentId !== null && $parentId === $id) {
            // Would make the row its own ancestor and hang any tree walk.
            $violations['parentId'] = 'An item cannot be its own parent.';
        }

        $description = isset($input['description']) ? trim((string) $input['description']) : null;

        if ($description !== null && mb_strlen($description) > 500) {
            $violations['description'] = 'Description must be 500 characters or fewer.';
        }

        // Unknown attributes are rejected, not quietly stored: a typo'd field
        // that silently persists is a bug nobody finds until a report is wrong.
        $attributes = [];
        $allowed = $kind->allowedAttributes();

        foreach ((array) ($input['attributes'] ?? []) as $key => $value) {
            $key = (string) $key;

            if (!in_array($key, $allowed, true)) {
                $violations['attributes'] = sprintf(
                    'Unknown field "%s". A %s accepts: %s.',
                    $key,
                    strtolower($kind->label()),
                    $allowed === [] ? 'none' : implode(', ', $allowed),
                );

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $attributes[$key] = $value === null ? null : (string) $value;
            }
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            kind: $kind,
            name: $name,
            code: $code,
            description: $description === '' ? null : $description,
            parentId: $parentId,
            sortOrder: (int) ($input['sortOrder'] ?? 0),
            isActive: filter_var($input['isActive'] ?? true, FILTER_VALIDATE_BOOL),
            attributes: $attributes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'id'          => $this->id,
            'kind'        => $this->kind->value,
            'name'        => $this->name,
            'description' => $this->description,
            'sortOrder'   => $this->sortOrder,
            'isActive'    => $this->isActive,
            'createdAt'   => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];

        // Omitted rather than sent as null, so a client never renders an empty
        // "Code" field for a kind that has none.
        if ($this->kind->usesCode()) {
            $payload['code'] = $this->code;
        }

        if ($this->kind->supportsHierarchy()) {
            $payload['parentId'] = $this->parentId;
        }

        if ($this->attributes !== []) {
            $payload['attributes'] = $this->attributes;
        }

        return $payload;
    }
}
