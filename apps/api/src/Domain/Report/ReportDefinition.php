<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

use Central\Api\Domain\Authz\Permission;

/**
 * What one report is, and who may run it.
 *
 * The `permission` field is the fix for the legacy's most serious reporting
 * defect. There, each entry carried an `audience` list — `['admin', 'hr']` —
 * which `getCatalog()` used to filter the *listing* and `runReport()` never
 * consulted at all. Any authenticated caller could run any of the 84 reports,
 * including the executive dashboard and the audit trail, by naming its key.
 *
 * Here the permission is checked when the report runs, and the catalogue
 * listing is derived from the same field, so the two cannot disagree.
 */
final readonly class ReportDefinition
{
    /**
     * @param list<string> $requires parameters without which the report is meaningless
     */
    public function __construct(
        public ReportKey $key,
        public ReportCategory $category,
        public string $name,
        public string $description,
        public ReportShape $shape,
        public Permission $permission = Permission::ReportView,
        public bool $exportable = false,
        public array $requires = [],
        /**
         * Whether the rows name individual people.
         *
         * Drives the row-level narrowing in {@see ReportScope}: a report of
         * departmental averages is safe to show a team lead, and the same
         * figures broken down by named colleague is not.
         */
        public bool $namesPeople = false,
    ) {
    }

    /**
     * Whether the caller supplied what this report needs.
     *
     * @param  array<string, mixed> $parameters
     * @return list<string>         the missing ones
     */
    public function missing(array $parameters): array
    {
        $missing = [];

        foreach ($this->requires as $name) {
            if (trim((string) ($parameters[$name] ?? '')) === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key'         => $this->key->value,
            'name'        => $this->name,
            'description' => $this->description,
            'category'    => $this->category->value,
            'shape'       => $this->shape->value,
            'exportable'  => $this->exportable,
            'requires'    => $this->requires,
            'paginated'   => $this->shape->isPaginated(),
        ];
    }
}
