<?php

declare(strict_types=1);

namespace Central\Api\Domain\Library;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * A shelf in the digital library: a tenant-named label its items are filed
 * under. It is not an audience. Who may see an item is still decided by the
 * item itself, so a category only groups, it never grants.
 */
final readonly class LibraryCategory
{
    public const NAME_MAX = 120;
    public const DESCRIPTION_MAX = 1000;

    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $name,
        public ?string $description = null,
        public int $sortOrder = 0,
        public int $itemCount = 0,
    ) {
    }

    /**
     * Builds a category from a request body. With `$existing` it is a partial
     * update: a field the body leaves out keeps its current value.
     *
     * @param array<string, mixed> $body
     *
     * @throws ValidationFailed
     */
    public static function fromInput(string $tenantId, array $body, ?self $existing = null): self
    {
        $violations = [];

        $name = $existing !== null ? $existing->name : '';
        if ($existing === null || array_key_exists('name', $body)) {
            $raw = $body['name'] ?? null;
            $name = is_string($raw) ? trim($raw) : '';
            if ($name === '') {
                $violations['name'] = 'Give the category a name.';
            } elseif (mb_strlen($name) > self::NAME_MAX) {
                $violations['name'] = sprintf('Keep the name to %d characters or fewer.', self::NAME_MAX);
            }
        }

        $description = $existing?->description;
        if (array_key_exists('description', $body)) {
            $raw = $body['description'];
            $description = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
            if ($description !== null && mb_strlen($description) > self::DESCRIPTION_MAX) {
                $violations['description'] = sprintf(
                    'Keep the description to %d characters or fewer.',
                    self::DESCRIPTION_MAX,
                );
            }
        }

        $sortOrder = $existing !== null ? $existing->sortOrder : 0;
        if (array_key_exists('sortOrder', $body) && $body['sortOrder'] !== null && $body['sortOrder'] !== '') {
            $parsed = self::wholeNumber($body['sortOrder']);
            if ($parsed === null) {
                $violations['sortOrder'] = 'The position must be a whole number.';
            } else {
                $sortOrder = $parsed;
            }
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $existing?->id,
            tenantId: $tenantId,
            name: $name,
            description: $description,
            sortOrder: $sortOrder,
            itemCount: $existing !== null ? $existing->itemCount : 0,
        );
    }

    /**
     * Whether a value is shaped like a category id, checked before it reaches a
     * UUID column, where a malformed one is a database error rather than a 422.
     */
    public static function looksLikeId(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
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
            'sortOrder'   => $this->sortOrder,
            'itemCount'   => $this->itemCount,
        ];
    }

    /** A whole number in the INTEGER range, from an int, an integral float or a numeric string. */
    private static function wholeNumber(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return abs($raw) < 1_000_000_000 ? $raw : null;
        }
        if (is_float($raw)) {
            return floor($raw) === $raw && abs($raw) < 1e9 ? (int) $raw : null;
        }
        if (is_string($raw) && preg_match('/^-?\d{1,9}$/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        return null;
    }
}
