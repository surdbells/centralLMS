<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Report\CustomReportRepository;
use Central\Api\Domain\Report\CustomReportResult;
use Central\Api\Domain\Report\CustomReportSpec;
use Central\Api\Domain\Report\CustomSource;
use Central\Api\Domain\Report\ReportColumn;
use Central\Api\Domain\Report\ReportCriteria;
use Central\Api\Domain\Report\ReportValueType;
use Doctrine\DBAL\Connection;

/**
 * Runs the custom report builder's queries.
 *
 * The sources and the SQL behind them are in {@see CustomReportSources}; this
 * only executes what it builds, and tidies the values on the way out.
 */
final class DbalCustomReportRepository implements CustomReportRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CustomReportSources $sources = new CustomReportSources(),
    ) {
    }

    public function sources(): array
    {
        return $this->sources->sources();
    }

    public function source(string $key): ?CustomSource
    {
        return $this->sources->source($key);
    }

    public function run(CustomReportSpec $spec, ReportCriteria $criteria): CustomReportResult
    {
        $statement = $this->sources->statement($spec, $criteria);

        $total = (int) $this->connection->fetchOne(
            $statement['count'],
            array_diff_key($statement['params'], ['limit' => true, 'offset' => true]),
            $statement['types'],
        );

        $rows = $this->connection->fetchAllAssociative(
            $statement['rows'],
            $statement['params'],
            $statement['types'],
        );

        return new CustomReportResult($spec, $this->normalise($rows, $spec->resultColumns()), $total);
    }

    /**
     * Numbers as numbers.
     *
     * PDO hands numerics back as strings, which serialise quoted and leave a
     * client to coerce before it can sort or chart. Only the numeric columns
     * are converted: an employee number like "00123" is text and stays so.
     *
     * @param  list<array<string, mixed>> $rows
     * @param  list<ReportColumn>         $columns
     * @return list<array<string, mixed>>
     */
    private function normalise(array $rows, array $columns): array
    {
        $numeric = [];

        foreach ($columns as $column) {
            if ($column->type->isNumeric() || $column->type === ReportValueType::Duration) {
                $numeric[$column->key] = true;
            }
        }

        return array_map(
            static function (array $row) use ($numeric): array {
                foreach ($row as $key => $value) {
                    if (isset($numeric[$key]) && is_string($value) && is_numeric($value)) {
                        $row[$key] = str_contains($value, '.') ? (float) $value : (int) $value;
                    }
                }

                return $row;
            },
            $rows,
        );
    }
}
