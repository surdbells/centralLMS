<?php

declare(strict_types=1);

namespace Central\Api\Domain\Audit;

/**
 * How a caller narrows the audit trail.
 *
 * A value it cannot parse is a violation, not something to drop. A filter that
 * silently does nothing is the worst of the three possible behaviours: the
 * caller asked to narrow, got everything back, and has no way to tell that
 * from a genuinely wide result. Refusing says which value was the problem.
 */
final readonly class AuditFilters
{
    private function __construct(
        public ?string $actorId = null,
        /** An exact route name, or a module prefix such as `users`. */
        public ?string $action = null,
        public ?string $subjectId = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $until = null,
        /** Failed attempts are the interesting ones, so they can be isolated. */
        public bool $failuresOnly = false,
    ) {
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $violations collected, so a caller learns
     *                                          about every bad value at once
     */
    public static function fromQuery(array $query, array &$violations = []): self
    {
        return new self(
            actorId: self::uuid($query['actorId'] ?? null, 'actorId', $violations),
            action: self::action($query['action'] ?? null, $violations),
            subjectId: self::uuid($query['subjectId'] ?? null, 'subjectId', $violations),
            from: self::date($query['from'] ?? null, 'from', $violations),
            until: self::date($query['until'] ?? null, 'until', $violations),
            failuresOnly: filter_var($query['failuresOnly'] ?? false, FILTER_VALIDATE_BOOL),
        );
    }

    /**
     * Route names are words and dots.
     *
     * The pattern is what keeps a `%` out of the LIKE this becomes. It is
     * enforced here rather than escaped at the query, because a caller sending
     * `%` did not mean a route name and should be told so.
     *
     * @param array<string, string> $violations
     */
    private static function action(mixed $value, array &$violations): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9]*(\.[a-z0-9]+)*$/i', $value) !== 1) {
            $violations['action'] = 'An action is a route name, such as "users" or "users.create".';

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, string> $violations
     */
    private static function uuid(mixed $value, string $field, array &$violations): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        if (preg_match('/^[0-9a-f-]{36}$/i', $value) !== 1) {
            $violations[$field] = 'This must be an id.';

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, string> $violations
     */
    private static function date(mixed $value, string $field, array &$violations): ?\DateTimeImmutable
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            $violations[$field] = 'This must be a date, such as 2026-08-31.';

            return null;
        }
    }
}
