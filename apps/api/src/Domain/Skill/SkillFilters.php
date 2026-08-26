<?php

declare(strict_types=1);

namespace Central\Api\Domain\Skill;

/**
 * How a caller narrows the skills dictionary.
 */
final readonly class SkillFilters
{
    private function __construct(
        public ?string $search = null,
        public ?string $categoryId = null,
        public ?Proficiency $level = null,
        public ?bool $activeOnly = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string> $violations
     */
    public static function fromQuery(array $query, array &$violations = []): self
    {
        $level = null;

        if (isset($query['level']) && trim((string) $query['level']) !== '') {
            $level = Proficiency::tryFrom(strtolower(trim((string) $query['level'])));

            if ($level === null) {
                $violations['level'] = 'Choose one of: ' . implode(', ', Proficiency::values()) . '.';
            }
        }

        return new self(
            search: self::text($query['search'] ?? null),
            categoryId: self::uuid($query['categoryId'] ?? null),
            level: $level,
            activeOnly: array_key_exists('active', $query)
                ? filter_var($query['active'], FILTER_VALIDATE_BOOL)
                : null,
        );
    }

    public static function all(): self
    {
        return new self();
    }

    /** What a picker wants: the ones still in use. */
    public static function active(): self
    {
        return new self(activeOnly: true);
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private static function uuid(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return preg_match('/^[0-9a-f-]{36}$/i', $value) === 1 ? $value : null;
    }
}
