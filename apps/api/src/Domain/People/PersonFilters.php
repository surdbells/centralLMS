<?php

declare(strict_types=1);

namespace Central\Api\Domain\People;

use Central\Api\Domain\Identity\UserStatus;

/**
 * How a caller narrows the people list.
 *
 * A value object rather than a bag of nullable parameters, so the repository
 * signature does not grow a tenth argument every time a screen adds a filter.
 */
final readonly class PersonFilters
{
    private function __construct(
        public ?string $search = null,
        public ?UserStatus $status = null,
        public ?string $branchId = null,
        public ?string $departmentId = null,
        public ?string $teamId = null,
        public ?string $levelId = null,
        public ?string $jobTitleId = null,
        public ?string $managerId = null,
        public ?string $roleId = null,
        /** Whether to resolve org-unit names and count enrolments. */
        public bool $withPlacement = false,
        public bool $withActivity = false,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $status = isset($query['status']) && is_string($query['status'])
            ? UserStatus::tryFrom(strtolower(trim($query['status'])))
            : null;

        return new self(
            search: self::text($query['search'] ?? null),
            status: $status,
            branchId: self::uuid($query['branchId'] ?? null),
            departmentId: self::uuid($query['departmentId'] ?? null),
            teamId: self::uuid($query['teamId'] ?? null),
            levelId: self::uuid($query['levelId'] ?? null),
            jobTitleId: self::uuid($query['jobTitleId'] ?? null),
            managerId: self::uuid($query['managerId'] ?? null),
            roleId: self::uuid($query['roleId'] ?? null),
            // Off by default: both cost joins, and most callers are filling a
            // picker rather than drawing a table.
            withPlacement: filter_var($query['withPlacement'] ?? false, FILTER_VALIDATE_BOOL),
            withActivity: filter_var($query['withActivity'] ?? false, FILTER_VALIDATE_BOOL),
        );
    }

    /** Everyone, with names resolved — what a management table wants. */
    public static function forTable(): self
    {
        return new self(withPlacement: true);
    }

    public function withOnly(string $roleId): self
    {
        return new self(
            $this->search,
            $this->status,
            $this->branchId,
            $this->departmentId,
            $this->teamId,
            $this->levelId,
            $this->jobTitleId,
            $this->managerId,
            $roleId,
            $this->withPlacement,
            $this->withActivity,
        );
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
