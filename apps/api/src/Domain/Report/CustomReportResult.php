<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * What a custom report produced.
 *
 * Serialised in the same envelope as {@see ReportResult} — `{report, columns,
 * rows, totals, series}` — so a client reads a built report exactly as it
 * reads one from the catalogue.
 */
final readonly class CustomReportResult
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        public CustomReportSpec $spec,
        public array $rows,
        /** Matching rows (or groups) before the limit. */
        public int $total,
        public ?string $note = null,
    ) {
    }

    public function withNote(string $note): self
    {
        return new self($this->spec, $this->rows, $this->total, $note);
    }

    /**
     * The rows as a table, for the CSV writer.
     *
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return $this->spec->resultColumns();
    }

    /**
     * Label/value pairs for a chart: one bar per group.
     *
     * Empty for an ungrouped report, whose rows are people rather than
     * quantities.
     *
     * @return list<array{label: string, value: int|float}>
     */
    public function series(): array
    {
        $groupBy = $this->spec->groupBy;

        if ($groupBy === null) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'label' => (string) ($row[$groupBy] ?? ''),
                'value' => is_numeric($row[CustomReportSpec::RECORDS] ?? null)
                    ? $row[CustomReportSpec::RECORDS] + 0
                    : 0,
            ],
            $this->rows,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $source = $this->spec->source;

        $payload = [
            'report'  => [
                'key'         => 'custom_' . $source->key,
                'name'        => $source->label,
                'description' => $source->description,
                'category'    => 'custom',
                'shape'       => $this->spec->isGrouped() ? 'breakdown' : 'table',
                'exportable'  => true,
                'requires'    => [],
                'paginated'   => false,
            ],
            'columns' => array_map(static fn (ReportColumn $c): array => $c->toArray(), $this->columns()),
            'rows'    => $this->rows,
            'totals'  => (object) [
                // Said, because a report limited to 50 rows out of 3,000 should
                // not read as the whole picture.
                'matching' => $this->total,
                'shown'    => count($this->rows),
            ],
            'series'  => $this->series(),
            'config'  => $this->spec->toConfig(),
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
        return [
            'total'   => $this->total,
            'page'    => 1,
            'perPage' => $this->spec->perPage,
        ];
    }
}
