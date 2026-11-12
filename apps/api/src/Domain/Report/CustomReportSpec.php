<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * A custom report, validated against its source.
 *
 * What the builder sends — a source, the columns to show, an optional
 * grouping, a sort and some filters — checked key by key against what the
 * source declares. Unknown columns, groupings and filter values are refused
 * with a message saying which; an unknown sort falls back to the default, so
 * a saved report whose sort column was later removed still runs.
 *
 * There is no tenant here and no scope: those come from whoever runs it, so a
 * saved spec can be shared without carrying anybody's rows with it.
 */
final readonly class CustomReportSpec
{
    public const DEFAULT_PER_PAGE = 50;

    /** The key of the row count in a grouped result. */
    public const RECORDS = 'records';

    /**
     * @param list<string>          $columns field keys, in display order
     * @param array<string, string> $filters filter key => accepted value
     */
    private function __construct(
        public CustomSource $source,
        public array $columns,
        public ?string $groupBy,
        public ?string $sortBy,
        public string $sortDirection,
        public int $perPage,
        public array $filters,
    ) {
    }

    /**
     * Reads either the API's camelCase or the builder's snake_case names, so
     * a config saved by the legacy screen reads the same as a new one.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string> $violations
     */
    public static function fromInput(CustomSource $source, array $input, array &$violations): ?self
    {
        // --- columns ------------------------------------------------------
        $requested = is_array($input['columns'] ?? null) ? $input['columns'] : [];
        $columns   = [];
        $unknown   = [];

        foreach ($requested as $key) {
            if (!is_string($key)) {
                continue;
            }

            $key = trim($key);

            if ($source->field($key) === null) {
                $unknown[] = $key;
            } elseif (!in_array($key, $columns, true)) {
                $columns[] = $key;
            }
        }

        if ($unknown !== []) {
            $violations['columns'] = sprintf(
                '%s has no column called %s.',
                $source->label,
                implode(', ', array_map(static fn (string $k): string => '"' . $k . '"', $unknown)),
            );
        } elseif ($columns === []) {
            $violations['columns'] = 'Choose at least one column.';
        }

        // --- grouping -----------------------------------------------------
        $groupBy = self::text($input['groupBy'] ?? $input['group_by'] ?? null);

        if ($groupBy !== null && !$source->hasGrouping($groupBy)) {
            $violations['groupBy'] = sprintf('%s cannot be grouped by "%s".', $source->label, $groupBy);
        }

        // --- direction and size -------------------------------------------
        $direction = strtolower((string) self::text($input['sortDirection'] ?? $input['sort_dir'] ?? 'asc'));

        if (!in_array($direction, ['asc', 'desc'], true)) {
            $violations['sortDirection'] = 'Sort ascending or descending.';
        }

        $perPage = (int) ($input['perPage'] ?? $input['per_page'] ?? $input['limit'] ?? self::DEFAULT_PER_PAGE);

        if ($perPage < 1 || $perPage > 500) {
            $violations['perPage'] = 'Ask for between 1 and 500 rows.';
        }

        // --- filters ------------------------------------------------------
        $filters = [];
        $given   = is_array($input['filters'] ?? null) ? $input['filters'] : [];

        foreach ($given as $key => $value) {
            if (!is_string($key) || $value === null || !is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $filter = $source->filter($key);

            if ($filter === null) {
                // Refused rather than ignored: a filter that silently does
                // nothing is how somebody asks for one department and exports
                // the organisation.
                $violations['filters.' . $key] = sprintf('%s cannot be filtered by "%s".', $source->label, $key);

                continue;
            }

            $accepted = $filter->accept((string) $value);

            if ($accepted === null) {
                $violations['filters.' . $key] = sprintf('That is not a value "%s" accepts.', $filter->label);

                continue;
            }

            $filters[$key] = $accepted;
        }

        if ($violations !== []) {
            return null;
        }

        $spec = new self($source, $columns, $groupBy, null, $direction, $perPage, $filters);

        // A sort the report no longer offers falls back to the default rather
        // than failing: a saved report should outlive a renamed column.
        $sortBy = self::text($input['sortBy'] ?? $input['sort_by'] ?? null);

        return $sortBy !== null && in_array($sortBy, $spec->sortable(), true)
            ? new self($source, $columns, $groupBy, $sortBy, $direction, $perPage, $filters)
            : $spec;
    }

    public function isGrouped(): bool
    {
        return $this->groupBy !== null;
    }

    /**
     * The fields shown, in order — in a grouped report only those with a
     * meaningful summary.
     *
     * @return list<CustomField>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->columns as $key) {
            $field = $this->source->field($key);

            if ($field === null || ($this->isGrouped() && !$field->isAggregatable())) {
                continue;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * The columns of the result.
     *
     * Grouped: the group, how many rows fell into it, then a summary of each
     * chosen field that has one. Ungrouped: the chosen fields as they are.
     *
     * @return list<ReportColumn>
     */
    public function resultColumns(): array
    {
        if ($this->groupBy === null) {
            return array_map(static fn (CustomField $f): ReportColumn => $f->column(), $this->fields());
        }

        return [
            ReportColumn::text($this->groupBy, $this->source->groupings[$this->groupBy]),
            ReportColumn::number(self::RECORDS, 'Records'),
            ...array_map(static fn (CustomField $f): ReportColumn => $f->aggregateColumn(), $this->fields()),
        ];
    }

    /**
     * The keys this report can be sorted by.
     *
     * @return list<string>
     */
    public function sortable(): array
    {
        $fields = array_map(static fn (CustomField $f): string => $f->key, $this->fields());

        return $this->groupBy === null
            ? $fields
            : [$this->groupBy, self::RECORDS, ...$fields];
    }

    /**
     * The criteria input this spec contributes: the date range and the size.
     *
     * Dates go through {@see ReportCriteria} so they are validated, and a
     * range's end covers the whole of its last day, exactly as every other
     * report treats them.
     *
     * @return array<string, mixed>
     */
    public function criteriaInput(): array
    {
        return array_filter([
            'from'          => $this->filters['from'] ?? null,
            'to'            => $this->filters['to'] ?? null,
            'perPage'       => $this->perPage,
            'page'          => 1,
            'sortDirection' => $this->sortDirection,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * The non-date filters, which the query applies itself.
     *
     * @return array<string, string>
     */
    public function rowFilters(): array
    {
        return array_filter(
            $this->filters,
            fn (string $_value, string $key): bool => !($this->source->filter($key)?->isDate() ?? false),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The spec in its canonical form — what a saved report stores.
     *
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        return [
            'source'        => $this->source->key,
            'columns'       => $this->columns,
            'groupBy'       => $this->groupBy,
            'sortBy'        => $this->sortBy,
            'sortDirection' => $this->sortDirection,
            'perPage'       => $this->perPage,
            'filters'       => (object) $this->filters,
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
