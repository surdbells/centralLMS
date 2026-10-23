<?php

declare(strict_types=1);

namespace Central\Api\Domain\CustomField;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * One tenant-defined profile field: its key, label, type and options.
 *
 * The definition, not the answer. A person's value for it lives in
 * `users.custom_fields`, keyed by this definition's {@see $key}. Validation
 * lives here, as it does on {@see \Central\Api\Domain\Lookup\Lookup}, so the
 * HTTP endpoint and any future import apply one set of rules.
 */
final readonly class CustomFieldDefinition
{
    /**
     * @param list<string> $options the allowed values, for a dropdown
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        /** Stable machine key the value map is keyed by; never renamed. */
        public string $key,
        public string $label,
        public CustomFieldType $type,
        public array $options = [],
        public bool $required = false,
        public int $sortOrder = 0,
        public bool $isActive = true,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Builds a validated definition from client input.
     *
     * On create, `$existingKey` and `$existingType` are null: a key is derived
     * from the label (or an explicit `key`) and the type is read from the input.
     * On update both are fixed — renaming the key would orphan every value
     * stored under the old one, and changing the type would strand values that
     * no longer coerce — so the caller passes the current values and any `key`
     * or `type` in the input is ignored.
     *
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(
        string $id,
        string $tenantId,
        array $input,
        ?string $existingKey = null,
        ?CustomFieldType $existingType = null,
    ): self {
        $violations = [];

        $label = trim((string) ($input['label'] ?? ''));

        if ($label === '') {
            $violations['label'] = 'Enter a field label.';
        } elseif (mb_strlen($label) > 100) {
            $violations['label'] = 'Label must be 100 characters or fewer.';
        }

        // Fixed once the field exists: a dropdown whose values are already
        // stored cannot quietly become a number field that rejects them.
        $type = $existingType ?? CustomFieldType::tryFrom(strtolower(trim((string) ($input['type'] ?? 'text'))));

        if ($type === null) {
            $violations['type'] = 'Choose one of: ' . implode(', ', array_map(
                static fn (CustomFieldType $t): string => $t->value,
                CustomFieldType::cases(),
            )) . '.';
            $type = CustomFieldType::Text;
        }

        // Only a dropdown has options, and it must have at least one — a
        // dropdown with nothing to choose is a field nobody can fill.
        $options = [];

        if ($type->usesOptions()) {
            foreach ((array) ($input['options'] ?? []) as $option) {
                $option = trim((string) $option);

                if ($option !== '' && !in_array($option, $options, true)) {
                    $options[] = $option;
                }
            }

            if ($options === []) {
                $violations['options'] = 'Add at least one option for a dropdown.';
            }
        }

        $key = $existingKey ?? self::deriveKey($input['key'] ?? null, $label);

        if ($existingKey === null && $key === '') {
            $violations['label'] = $violations['label'] ?? 'Enter a label that contains a letter or number.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            key: $key,
            label: $label,
            type: $type,
            options: $options,
            required: filter_var($input['required'] ?? false, FILTER_VALIDATE_BOOL),
            sortOrder: (int) ($input['sortOrder'] ?? 0),
            isActive: filter_var($input['isActive'] ?? true, FILTER_VALIDATE_BOOL),
        );
    }

    /**
     * A stable key from an explicit value or, failing that, the label.
     *
     * Lower snake case, so "Organization / Subsidiary" becomes
     * `organization_subsidiary` — a safe JSON object key and a usable column
     * name should these ever need one.
     */
    private static function deriveKey(mixed $explicit, string $label): string
    {
        $source = trim((string) ($explicit ?? ''));

        if ($source === '') {
            $source = $label;
        }

        $key = strtolower($source);
        $key = (string) preg_replace('/[^a-z0-9]+/', '_', $key);

        return trim($key, '_');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'id'        => $this->id,
            'key'       => $this->key,
            'label'     => $this->label,
            'type'      => $this->type->value,
            'required'  => $this->required,
            'sortOrder' => $this->sortOrder,
            'isActive'  => $this->isActive,
            'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];

        // Sent only for the type that has them, so a client never renders an
        // empty options editor for a text field.
        if ($this->type->usesOptions()) {
            $payload['options'] = $this->options;
        }

        return $payload;
    }
}
