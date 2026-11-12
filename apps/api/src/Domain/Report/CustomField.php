<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * One column the custom report builder offers for a data source.
 *
 * Metadata only. The SQL behind it lives with the source definitions in the
 * persistence layer, keyed by {@see $key}; a request names keys and nothing
 * else, so no part of a caller's input is ever interpolated into a query.
 */
final readonly class CustomField
{
    /** Aggregations a grouped report may apply to a field. */
    public const AGGREGATES = ['avg', 'sum', 'min', 'max'];

    public function __construct(
        public string $key,
        public string $label,
        public ReportValueType $type = ReportValueType::Text,
        /**
         * How the field is summarised when the report is grouped — `avg`,
         * `sum`, `min` or `max` — or null when it has no meaningful summary
         * (a name, an email) and is left out of a grouped report.
         */
        public ?string $aggregate = null,
    ) {
        if ($aggregate !== null && !in_array($aggregate, self::AGGREGATES, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an aggregate.', $aggregate));
        }
    }

    public function isAggregatable(): bool
    {
        return $this->aggregate !== null;
    }

    /**
     * The column as it appears in an ungrouped result.
     */
    public function column(): ReportColumn
    {
        return new ReportColumn($this->key, $this->label, $this->type);
    }

    /**
     * The column as it appears in a grouped result, labelled with its summary.
     */
    public function aggregateColumn(): ReportColumn
    {
        $prefix = match ($this->aggregate) {
            'avg'   => 'Average',
            'sum'   => 'Total',
            'min'   => 'Lowest',
            'max'   => 'Highest',
            default => '',
        };

        return new ReportColumn(
            $this->key,
            $prefix === '' ? $this->label : sprintf('%s %s', $prefix, mb_strtolower($this->label)),
            $this->type,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key'       => $this->key,
            'label'     => $this->label,
            'type'      => $this->type->value,
            'aggregate' => $this->aggregate,
        ];
    }
}
