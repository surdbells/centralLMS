<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * A data source the custom report builder can query — enrolments, people,
 * assessment attempts — and what it offers: columns, groupings, filters.
 *
 * Every key a request may name is declared here, and a request naming
 * anything else is refused (columns, groupings, filters) or falls back
 * (sorting). That is the whole of the builder's defence against injection:
 * the SQL behind each key is fixed in the persistence layer and a caller only
 * ever chooses between them.
 */
final readonly class CustomSource
{
    /**
     * @param list<CustomField>     $fields
     * @param array<string, string> $groupings key => label
     * @param list<CustomFilter>    $filters
     * @param list<string>          $defaultColumns what the builder ticks when the source is chosen
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public array $fields,
        public array $groupings,
        public array $filters,
        public array $defaultColumns = [],
        /**
         * Whether a row names a person. Such a source is narrowed to the
         * caller's row scope, and exporting it needs `report.export`.
         */
        public bool $namesPeople = true,
    ) {
    }

    public function field(string $key): ?CustomField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    public function hasGrouping(string $key): bool
    {
        return isset($this->groupings[$key]);
    }

    public function filter(string $key): ?CustomFilter
    {
        foreach ($this->filters as $filter) {
            if ($filter->key === $key) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * The listing entry: enough to fill a picker.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key'         => $this->key,
            'label'       => $this->label,
            'description' => $this->description,
            'namesPeople' => $this->namesPeople,
        ];
    }

    /**
     * Everything a client needs to build a report over this source.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $groupings = [];

        foreach ($this->groupings as $key => $label) {
            $groupings[] = ['key' => $key, 'label' => $label];
        }

        return [
            ...$this->toArray(),
            'columns'        => array_map(static fn (CustomField $f): array => $f->toArray(), $this->fields),
            'groupings'      => $groupings,
            'filters'        => array_map(static fn (CustomFilter $f): array => $f->toArray(), $this->filters),
            'defaultColumns' => $this->defaultColumns,
        ];
    }
}
