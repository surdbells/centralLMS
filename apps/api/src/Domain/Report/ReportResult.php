<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * What a report produced.
 *
 * One shape for all five kinds. A summary fills `totals`, a table fills
 * `columns` and `rows`, a series or a breakdown fills `series`, and a detail
 * report fills whichever it needs. The alternative — a different payload per
 * kind — is what left the legacy's client guessing what it had been handed.
 */
final readonly class ReportResult
{
    /**
     * @param list<ReportColumn>              $columns
     * @param list<array<string, mixed>>      $rows
     * @param array<string, int|float|string|null> $totals
     * @param list<array<string, mixed>>      $series label/value pairs
     */
    public function __construct(
        public ReportDefinition $definition,
        public array $columns = [],
        public array $rows = [],
        public array $totals = [],
        public array $series = [],
        /** Total matching rows, before paging. Null when not paginated. */
        public ?int $total = null,
        public ?ReportCriteria $criteria = null,
        /**
         * Something the reader needs to know to read the figures correctly —
         * a caveat about coverage, or that their view is narrowed.
         */
        public ?string $note = null,
    ) {
    }

    /**
     * @param list<ReportColumn>         $columns
     * @param list<array<string, mixed>> $rows
     */
    public static function table(
        ReportDefinition $definition,
        array $columns,
        array $rows,
        int $total,
        ReportCriteria $criteria,
    ): self {
        return new self($definition, $columns, $rows, [], [], $total, $criteria);
    }

    /**
     * @param array<string, int|float|string|null> $totals
     * @param list<array<string, mixed>>      $series
     */
    public static function summary(
        ReportDefinition $definition,
        array $totals,
        array $series = [],
        ?ReportCriteria $criteria = null,
    ): self {
        return new self($definition, [], [], $totals, $series, null, $criteria);
    }

    /**
     * @param list<array<string, mixed>>      $series
     * @param array<string, int|float|string|null> $totals
     */
    public static function series(
        ReportDefinition $definition,
        array $series,
        array $totals = [],
        ?ReportCriteria $criteria = null,
    ): self {
        return new self($definition, [], [], $totals, $series, null, $criteria);
    }

    /**
     * The same result with a note attached.
     */
    public function withNote(string $note): self
    {
        return new self(
            $this->definition,
            $this->columns,
            $this->rows,
            $this->totals,
            $this->series,
            $this->total,
            $this->criteria,
            $note,
        );
    }

    public function isEmpty(): bool
    {
        return $this->rows === [] && $this->series === [] && $this->totals === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'report'  => $this->definition->toArray(),
            'columns' => array_map(static fn (ReportColumn $c): array => $c->toArray(), $this->columns),
            'rows'    => $this->rows,
            // Objects, not arrays: an empty totals set must serialise as {}
            // so a client can read `totals.completed` without checking first.
            'totals'  => (object) $this->totals,
            'series'  => $this->series,
        ];

        if ($this->note !== null) {
            $payload['note'] = $this->note;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $meta = ['criteria' => $this->criteria?->toArray() ?? []];

        if ($this->total !== null && $this->criteria !== null) {
            $meta['total']      = $this->total;
            $meta['page']       = $this->criteria->page;
            $meta['perPage']    = $this->criteria->perPage;
            $meta['totalPages'] = (int) ceil($this->total / max(1, $this->criteria->perPage));
        }

        return $meta;
    }
}
