<?php

declare(strict_types=1);

namespace Central\Api\Domain\Note;

/**
 * How a learner narrows their own notes.
 *
 * One filter object where the legacy had five endpoints — `lesson-notes`,
 * `course-notes`, `pinned`, `recent` and `search` — each a copy of the same
 * query with one clause changed, and each with its own idea of the sort order.
 */
final readonly class NoteFilters
{
    private function __construct(
        public ?string $search = null,
        public ?string $lessonId = null,
        public ?string $courseId = null,
        public ?bool $pinnedOnly = null,
        /** `recent` orders by when it was last touched rather than pinned-first. */
        public bool $mostRecentFirst = false,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        return new self(
            search: self::text($query['search'] ?? null),
            lessonId: self::uuid($query['lessonId'] ?? null),
            courseId: self::uuid($query['courseId'] ?? null),
            pinnedOnly: array_key_exists('pinned', $query)
                ? filter_var($query['pinned'], FILTER_VALIDATE_BOOL)
                : null,
            mostRecentFirst: filter_var($query['recent'] ?? false, FILTER_VALIDATE_BOOL),
        );
    }

    public static function all(): self
    {
        return new self();
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
