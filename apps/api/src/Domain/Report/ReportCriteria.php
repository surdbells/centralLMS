<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * Everything one run of a report needs, validated.
 *
 * **The tenant is not a parameter.** It arrives inside {@see ReportScope},
 * which is built from the authenticated caller and cannot be constructed from
 * a request body. That is the structural fix for the live defect recorded as
 * action 0c: `api/reports.php` reads `tenant_id` from the POST body and hands
 * it to `setTenant()`, so every one of the 190 report queries scopes
 * faithfully — to whichever organisation the caller named.
 *
 * Filters here narrow *within* the scope. They can only ever remove rows.
 */
final readonly class ReportCriteria
{
    private const MAX_PER_PAGE = 500;

    /**
     * @param array<string, string> $filters arbitrary narrowing, validated per report
     */
    private function __construct(
        public ReportScope $scope,
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $to,
        public ?string $courseId,
        public ?string $departmentId,
        public ?string $teamId,
        public ?string $branchId,
        public ?string $learnerId,
        public ?string $assessmentId,
        public int $page,
        public int $perPage,
        public ?string $sortBy,
        public string $sortDirection,
        public array $filters,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string> $violations
     */
    public static function fromInput(
        ReportScope $scope,
        array $input,
        array &$violations,
    ): ?self {
        $from = self::date($input['from'] ?? null, 'from', $violations);
        $to   = self::date($input['to'] ?? null, 'to', $violations);

        // A range that runs backwards returns nothing and looks like missing
        // data rather than a mistake in the question.
        if ($from !== null && $to !== null && $from > $to) {
            $violations['from'] = 'The start of the range is after its end.';
        }

        $direction = strtolower(trim((string) ($input['sortDirection'] ?? 'desc')));

        if (!in_array($direction, ['asc', 'desc'], true)) {
            $violations['sortDirection'] = 'Sort ascending or descending.';
        }

        $perPage = (int) ($input['perPage'] ?? 25);

        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            $violations['perPage'] = sprintf('Ask for between 1 and %d rows.', self::MAX_PER_PAGE);
        }

        if ($violations !== []) {
            return null;
        }

        return new self(
            scope: $scope,
            from: $from,
            // The end of a date range is a day, and people mean all of it.
            // Without this, "to: 2026-03-31" silently excludes everything that
            // happened on the 31st.
            to: $to?->setTime(23, 59, 59),
            courseId: self::uuid($input['courseId'] ?? null),
            departmentId: self::uuid($input['departmentId'] ?? null),
            teamId: self::uuid($input['teamId'] ?? null),
            branchId: self::uuid($input['branchId'] ?? null),
            learnerId: self::uuid($input['learnerId'] ?? null),
            assessmentId: self::uuid($input['assessmentId'] ?? null),
            page: max(1, (int) ($input['page'] ?? 1)),
            perPage: $perPage,
            sortBy: self::text($input['sortBy'] ?? null),
            sortDirection: $direction,
            filters: self::filters($input['filters'] ?? null),
        );
    }

    /**
     * The same criteria over a different page.
     */
    public function onPage(int $page, ?int $perPage = null): self
    {
        return new self(
            $this->scope,
            $this->from,
            $this->to,
            $this->courseId,
            $this->departmentId,
            $this->teamId,
            $this->branchId,
            $this->learnerId,
            $this->assessmentId,
            max(1, $page),
            $perPage === null ? $this->perPage : max(1, min(self::MAX_PER_PAGE, $perPage)),
            $this->sortBy,
            $this->sortDirection,
            $this->filters,
        );
    }

    /**
     * Every row, for an export.
     *
     * Still capped. An export that streams a million rows through PHP is a
     * way to take the API down, and a spreadsheet nobody can open.
     */
    public function forExport(): self
    {
        return $this->onPage(1, self::MAX_PER_PAGE);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * The chosen sort, if it is one this report offers.
     *
     * Column names cannot be interpolated into SQL from a request body, so the
     * caller's choice is matched against a list the report declares. Anything
     * else falls back rather than erroring — a stale bookmark with an old
     * column name should still show the report.
     *
     * @param list<string> $allowed
     */
    public function sortColumn(array $allowed, string $fallback): string
    {
        return in_array($this->sortBy, $allowed, true) ? (string) $this->sortBy : $fallback;
    }

    public function sortOrder(): string
    {
        return $this->sortDirection === 'asc' ? 'ASC' : 'DESC';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'from'         => $this->from?->format('Y-m-d'),
            'to'           => $this->to?->format('Y-m-d'),
            'courseId'     => $this->courseId,
            'departmentId' => $this->departmentId,
            'teamId'       => $this->teamId,
            'branchId'     => $this->branchId,
            'learnerId'    => $this->learnerId,
            'assessmentId' => $this->assessmentId,
            'page'         => $this->page,
            'perPage'      => $this->perPage,
            'sortBy'       => $this->sortBy,
            'sortDirection' => $this->sortDirection,
            'filters'      => $this->filters === [] ? null : $this->filters,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, string> $violations
     */
    private static function date(mixed $value, string $field, array &$violations): ?\DateTimeImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            $violations[$field] = 'Use a date in the form 2026-03-01.';

            return null;
        }
    }

    private static function uuid(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        // Not a validity check — the query will find nothing for a bad id.
        // This only keeps obvious rubbish out of a bound parameter.
        return preg_match('/^[0-9a-f-]{36}$/i', $value) === 1 ? $value : null;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    private static function filters(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $filters = [];

        foreach ($value as $name => $filter) {
            if (is_string($name) && is_scalar($filter)) {
                $filters[$name] = trim((string) $filter);
            }
        }

        return $filters;
    }
}
