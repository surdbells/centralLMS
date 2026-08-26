<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * Runs a report.
 *
 * One method, deliberately. The legacy had twelve classes with 84 public
 * methods between them, each taking its scope through setters — which is how
 * `setTenant()` came to accept a value from a request body without anything
 * noticing.
 *
 * Here the only way in carries a {@see ReportCriteria}, whose tenant lives
 * inside a {@see ReportScope} built from the authenticated caller. There is no
 * setter and no second entry point.
 */
interface ReportRepository
{
    public function run(ReportDefinition $definition, ReportCriteria $criteria): ReportResult;

    /**
     * The people a caller may run reports about, for a picker.
     *
     * Honours the row scope, so a team lead's learner picker holds their own
     * reports rather than the whole organisation — which is also what stops
     * `individual_progress` being run against a colleague by guessing an id.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function selectableLearners(ReportScope $scope, ?string $search, int $page, int $perPage): array;

    /**
     * Whether the scope covers this person.
     *
     * Asked once, at the edge, before a report about a named learner runs.
     */
    public function scopeCovers(ReportScope $scope, string $userId): bool;
}
