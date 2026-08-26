<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Report\ReportCriteria;

/**
 * Assembles the WHERE clause every report shares.
 *
 * This class exists to make one mistake impossible. In the legacy each of the
 * 190 report queries wrote `WHERE ce.tenant_id = ?` by hand and passed the
 * tenant in — a convention held 190 times, and one that was already being
 * handed the wrong tenant from a request body (action 0c).
 *
 * Here a report cannot obtain a WHERE clause without one: {@see tenant()} is
 * the only constructor, it takes the criteria rather than an id, and the
 * criteria carry the tenant inside a scope built from the authenticated
 * caller. There is nowhere to pass a different one.
 */
final class ReportQuery
{
    /** @var list<string> */
    private array $clauses = [];

    /** @var array<string, mixed> */
    private array $params = [];

    /** @var array<string, mixed> */
    private array $types = [];

    private function __construct()
    {
    }

    /**
     * Starts a clause scoped to the caller's tenant.
     *
     * `$alias` is the table holding `tenant_id`; `$userAlias` is the one
     * holding the person a row is about, for the row-level narrowing. Pass
     * null for the second when the report has no per-person rows — a course
     * catalogue does not.
     */
    public static function tenant(ReportCriteria $criteria, string $alias, ?string $userAlias = null): self
    {
        $query = new self();

        $query->where(sprintf('%s.tenant_id = :tenantId', $alias), 'tenantId', $criteria->scope->tenantId);

        $scope = $criteria->scope;

        if ($userAlias !== null) {
            if ($scope->subjectId !== null) {
                // A report about one named person. The right to see them was
                // settled before this ran; here it only narrows.
                $query->where(sprintf('%s.id = :subjectId', $userAlias), 'subjectId', $scope->subjectId);
            } elseif (!$scope->tenantWide) {
                // Themselves and their direct reports. Without this a team
                // lead running "overdue training" sees the organisation.
                $query->where(
                    sprintf('(%s.id = :viewerId OR %s.manager_id = :viewerId)', $userAlias, $userAlias),
                    'viewerId',
                    $scope->viewerId,
                );
            }
        }

        return $query;
    }

    /**
     * The filters a caller may apply, for the tables that carry them.
     *
     * Only ever narrows. Every clause is `AND`ed onto a scope already fixed.
     */
    public function withScopeFilters(
        ReportCriteria $criteria,
        ?string $userAlias = null,
        ?string $courseAlias = null,
    ): self {
        if ($userAlias !== null) {
            $this->maybe($criteria->departmentId, sprintf('%s.department_id = :departmentId', $userAlias), 'departmentId');
            $this->maybe($criteria->teamId, sprintf('%s.team_id = :teamId', $userAlias), 'teamId');
            $this->maybe($criteria->branchId, sprintf('%s.branch_id = :branchId', $userAlias), 'branchId');
            $this->maybe($criteria->learnerId, sprintf('%s.id = :learnerId', $userAlias), 'learnerId');
        }

        if ($courseAlias !== null) {
            $this->maybe($criteria->courseId, sprintf('%s.id = :courseId', $courseAlias), 'courseId');
        }

        return $this;
    }

    /**
     * Restricts to a date range on one column.
     */
    public function betweenDates(ReportCriteria $criteria, string $column): self
    {
        if ($criteria->from !== null) {
            $this->where(
                sprintf('%s >= :dateFrom', $column),
                'dateFrom',
                $criteria->from->format('Y-m-d H:i:s.uP'),
            );
        }

        if ($criteria->to !== null) {
            $this->where(
                sprintf('%s <= :dateTo', $column),
                'dateTo',
                $criteria->to->format('Y-m-d H:i:s.uP'),
            );
        }

        return $this;
    }

    public function where(string $clause, ?string $param = null, mixed $value = null, mixed $type = null): self
    {
        $this->clauses[] = $clause;

        if ($param !== null) {
            $this->params[$param] = $value;

            if ($type !== null) {
                $this->types[$param] = $type;
            }
        }

        return $this;
    }

    /**
     * Adds the clause only when the value is present.
     */
    public function maybe(mixed $value, string $clause, string $param, mixed $type = null): self
    {
        if ($value !== null && $value !== '') {
            $this->where($clause, $param, $value, $type);
        }

        return $this;
    }

    /**
     * The clause, always beginning with a tenant condition.
     */
    public function sql(): string
    {
        return implode(' AND ', $this->clauses);
    }

    /**
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * @return array<string, mixed>
     */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * The same clause with extra parameters, for a query that adds its own.
     *
     * @param  array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function paramsWith(array $extra): array
    {
        return [...$this->params, ...$extra];
    }
}
