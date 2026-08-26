<?php

declare(strict_types=1);

namespace Central\Api\Domain\Media;

/**
 * How a caller narrows the media library.
 */
final readonly class MediaFilters
{
    private function __construct(
        public ?string $search = null,
        public ?MediaKind $kind = null,
        public ?string $courseId = null,
        public ?string $uploadedBy = null,
        /**
         * Off by default: the library shows one card per file, not one per
         * revision, and a course that has replaced a video four times would
         * otherwise fill the screen with it.
         */
        public bool $includeOldVersions = false,
    ) {
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string> $violations
     */
    public static function fromQuery(array $query, array &$violations = []): self
    {
        $kind = null;

        if (isset($query['kind']) && trim((string) $query['kind']) !== '') {
            $kind = MediaKind::tryFrom(strtolower(trim((string) $query['kind'])));

            if ($kind === null) {
                // Not silently ignored: an unknown kind that returns the whole
                // library looks like a working filter showing everything.
                $violations['kind'] = 'Choose one of: ' . implode(', ', MediaKind::values()) . '.';
            }
        }

        return new self(
            search: self::text($query['search'] ?? null),
            kind: $kind,
            courseId: self::uuid($query['courseId'] ?? null),
            uploadedBy: self::uuid($query['uploadedBy'] ?? null),
            includeOldVersions: filter_var($query['includeOldVersions'] ?? false, FILTER_VALIDATE_BOOL),
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
