<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

use Central\Api\Domain\Catalog\CourseStatus;

/**
 * How a caller narrows the list of paths.
 */
final readonly class PathFilters
{
    private function __construct(
        public ?string $search = null,
        public ?CourseStatus $status = null,
        public ?string $categoryId = null,
        public ?PathPacing $pacing = null,
        public ?bool $mandatoryOnly = null,
        /** Only the ones a learner may see and join. */
        public bool $catalogueOnly = false,
    ) {
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string> $violations
     */
    public static function fromQuery(array $query, array &$violations = []): self
    {
        $status = null;

        if (isset($query['status']) && trim((string) $query['status']) !== '') {
            $status = CourseStatus::tryFrom(strtolower(trim((string) $query['status'])));

            if ($status === null) {
                $violations['status'] = 'Choose draft, published or archived.';
            }
        }

        $pacing = null;

        if (isset($query['pacing']) && trim((string) $query['pacing']) !== '') {
            $pacing = PathPacing::tryFrom(strtolower(trim((string) $query['pacing'])));

            if ($pacing === null) {
                $violations['pacing'] = 'Choose one of: ' . implode(', ', PathPacing::values()) . '.';
            }
        }

        return new self(
            search: self::text($query['search'] ?? null),
            status: $status,
            categoryId: self::uuid($query['categoryId'] ?? null),
            pacing: $pacing,
            mandatoryOnly: array_key_exists('mandatory', $query)
                ? filter_var($query['mandatory'], FILTER_VALIDATE_BOOL)
                : null,
        );
    }

    public static function all(): self
    {
        return new self();
    }

    /**
     * What a learner browsing may see: published, and not private.
     */
    public static function catalogue(): self
    {
        return new self(status: CourseStatus::Published, catalogueOnly: true);
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
