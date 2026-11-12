<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * The data sources behind the custom report builder, and running a report
 * over one of them.
 *
 * Like {@see ReportRepository}, the only way to run carries a
 * {@see ReportCriteria}, whose tenant lives inside a scope built from the
 * authenticated caller.
 */
interface CustomReportRepository
{
    /**
     * @return list<CustomSource>
     */
    public function sources(): array;

    public function source(string $key): ?CustomSource;

    public function run(CustomReportSpec $spec, ReportCriteria $criteria): CustomReportResult;
}
