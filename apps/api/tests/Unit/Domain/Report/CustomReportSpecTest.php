<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Report;

use Central\Api\Domain\Report\CustomField;
use Central\Api\Domain\Report\CustomFilter;
use Central\Api\Domain\Report\CustomReportResult;
use Central\Api\Domain\Report\CustomReportSpec;
use Central\Api\Domain\Report\CustomSource;
use Central\Api\Domain\Report\ReportValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the custom report builder may ask of a source, and what it may not.
 *
 * Every key a request names is checked against the source, because the SQL
 * behind a key is chosen by it: an unchecked key is an injection point.
 */
#[CoversClass(CustomReportSpec::class)]
#[CoversClass(CustomFilter::class)]
#[CoversClass(CustomReportResult::class)]
final class CustomReportSpecTest extends TestCase
{
    #[Test]
    public function a_valid_config_is_accepted_in_the_order_given(): void
    {
        $spec = $this->spec([
            'columns'       => ['course', 'learner', 'course'],
            'sortBy'        => 'learner',
            'sortDirection' => 'DESC',
            'perPage'       => 100,
        ]);

        self::assertSame(['course', 'learner'], $spec->columns, 'Duplicates are dropped, order kept.');
        self::assertSame('learner', $spec->sortBy);
        self::assertSame('desc', $spec->sortDirection);
        self::assertSame(100, $spec->perPage);
        self::assertFalse($spec->isGrouped());
    }

    #[Test]
    public function the_builders_snake_case_names_are_read_too(): void
    {
        $spec = $this->spec([
            'columns'  => ['learner', 'progress'],
            'group_by' => 'department',
            'sort_by'  => 'progress',
            'sort_dir' => 'asc',
            'per_page' => 25,
        ]);

        self::assertSame('department', $spec->groupBy);
        self::assertSame('progress', $spec->sortBy);
        self::assertSame(25, $spec->perPage);
    }

    #[Test]
    public function a_column_the_source_does_not_have_is_refused(): void
    {
        $violations = [];

        $spec = CustomReportSpec::fromInput(
            $this->source(),
            ['columns' => ['learner', 'password_hash']],
            $violations,
        );

        self::assertNull($spec);
        self::assertArrayHasKey('columns', $violations);
        self::assertStringContainsString('password_hash', $violations['columns']);
    }

    #[Test]
    public function at_least_one_column_is_required(): void
    {
        $violations = [];

        self::assertNull(CustomReportSpec::fromInput($this->source(), ['columns' => []], $violations));
        self::assertArrayHasKey('columns', $violations);
    }

    #[Test]
    public function a_grouping_the_source_does_not_offer_is_refused(): void
    {
        $violations = [];

        self::assertNull(CustomReportSpec::fromInput(
            $this->source(),
            ['columns' => ['learner'], 'groupBy' => 'u.password'],
            $violations,
        ));
        self::assertArrayHasKey('groupBy', $violations);
    }

    #[Test]
    public function an_unknown_sort_falls_back_rather_than_failing(): void
    {
        // A saved report should outlive a renamed column.
        $spec = $this->spec(['columns' => ['learner'], 'sortBy' => 'progress; DROP TABLE users']);

        self::assertNull($spec->sortBy);
    }

    #[Test]
    public function a_sort_on_a_column_not_shown_falls_back(): void
    {
        $spec = $this->spec(['columns' => ['learner'], 'sortBy' => 'progress']);

        self::assertNull($spec->sortBy);
    }

    #[Test]
    public function a_grouped_report_shows_the_group_a_count_and_summaries_only(): void
    {
        $spec = $this->spec([
            'columns' => ['learner', 'progress', 'time_spent'],
            'groupBy' => 'department',
        ]);

        $keys = array_map(static fn ($c): string => $c->key, $spec->resultColumns());

        // The learner's name has no summary, so a grouped report leaves it out.
        self::assertSame(['department', CustomReportSpec::RECORDS, 'progress', 'time_spent'], $keys);
        self::assertSame('Average progress', $spec->resultColumns()[2]->label);
        self::assertSame('Total time spent', $spec->resultColumns()[3]->label);
    }

    #[Test]
    public function a_grouped_report_may_sort_by_the_group_or_the_count(): void
    {
        $byCount = $this->spec(['columns' => ['learner'], 'groupBy' => 'department', 'sortBy' => 'records']);
        $byGroup = $this->spec(['columns' => ['learner'], 'groupBy' => 'department', 'sortBy' => 'department']);

        self::assertSame('records', $byCount->sortBy);
        self::assertSame('department', $byGroup->sortBy);
    }

    #[Test]
    public function an_unknown_filter_is_refused_not_ignored(): void
    {
        // A filter that silently does nothing is how somebody asks for one
        // department and exports the organisation.
        $violations = [];

        self::assertNull(CustomReportSpec::fromInput(
            $this->source(),
            ['columns' => ['learner'], 'filters' => ['tenantId' => 'someone-elses']],
            $violations,
        ));
        self::assertArrayHasKey('filters.tenantId', $violations);
    }

    #[Test]
    public function a_select_filter_accepts_only_its_options(): void
    {
        $violations = [];

        self::assertNull(CustomReportSpec::fromInput(
            $this->source(),
            ['columns' => ['learner'], 'filters' => ['status' => "completed' OR '1'='1"]],
            $violations,
        ));
        self::assertArrayHasKey('filters.status', $violations);

        $spec = $this->spec(['columns' => ['learner'], 'filters' => ['status' => 'completed']]);

        self::assertSame(['status' => 'completed'], $spec->rowFilters());
    }

    #[Test]
    public function a_lookup_filter_takes_an_id_and_blank_filters_are_skipped(): void
    {
        $violations = [];

        self::assertNull(CustomReportSpec::fromInput(
            $this->source(),
            ['columns' => ['learner'], 'filters' => ['departmentId' => 'Sales']],
            $violations,
        ));

        $spec = $this->spec([
            'columns' => ['learner'],
            'filters' => ['departmentId' => 'AAAAAAAA-1111-4111-8111-111111111111', 'status' => ''],
        ]);

        self::assertSame(['departmentId' => 'aaaaaaaa-1111-4111-8111-111111111111'], $spec->rowFilters());
    }

    #[Test]
    public function dates_go_to_the_criteria_and_not_into_the_row_filters(): void
    {
        $spec = $this->spec([
            'columns' => ['learner'],
            'filters' => ['from' => '2026-01-01', 'to' => '2026-03-31', 'status' => 'completed'],
        ]);

        self::assertSame(['status' => 'completed'], $spec->rowFilters());
        self::assertSame('2026-01-01', $spec->criteriaInput()['from']);
        self::assertSame('2026-03-31', $spec->criteriaInput()['to']);
        self::assertArrayNotHasKey('tenantId', $spec->criteriaInput());
    }

    #[Test]
    public function the_saved_config_reads_back_as_the_same_spec(): void
    {
        $spec = $this->spec([
            'columns'  => ['learner', 'progress'],
            'groupBy'  => 'department',
            'sortBy'   => 'progress',
            'perPage'  => 25,
            'filters'  => ['status' => 'completed'],
        ]);

        $config = json_decode((string) json_encode($spec->toConfig()), true);
        self::assertIsArray($config);

        $again = $this->spec($config);

        self::assertEquals($spec->toConfig(), $again->toConfig());
        self::assertArrayNotHasKey('tenantId', $config);
    }

    #[Test]
    public function a_grouped_result_draws_one_bar_per_group(): void
    {
        $spec   = $this->spec(['columns' => ['progress'], 'groupBy' => 'department']);
        $result = new CustomReportResult($spec, [
            ['department' => 'Sales', 'records' => 12, 'progress' => 40.5],
            ['department' => 'Not set', 'records' => 3, 'progress' => 10.0],
        ], 2);

        self::assertSame(
            [['label' => 'Sales', 'value' => 12], ['label' => 'Not set', 'value' => 3]],
            $result->series(),
        );

        $payload = $result->toArray();
        self::assertSame('custom_enrollments', $payload['report']['key']);
        self::assertCount(3, $payload['columns']);
        self::assertEquals((object) ['matching' => 2, 'shown' => 2], $payload['totals']);
    }

    #[Test]
    public function an_unknown_aggregate_cannot_be_declared(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CustomField('x', 'X', ReportValueType::Number, 'median');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function spec(array $input): CustomReportSpec
    {
        $violations = [];
        $spec       = CustomReportSpec::fromInput($this->source(), $input, $violations);

        self::assertSame([], $violations);
        self::assertNotNull($spec);

        return $spec;
    }

    private function source(): CustomSource
    {
        return new CustomSource(
            'enrollments',
            'Course enrolments',
            'One row per learner per course.',
            [
                new CustomField('learner', 'Learner'),
                new CustomField('course', 'Course'),
                new CustomField('progress', 'Progress', ReportValueType::Percent, 'avg'),
                new CustomField('time_spent', 'Time spent', ReportValueType::Duration, 'sum'),
            ],
            ['department' => 'Department', 'status' => 'Status'],
            [
                CustomFilter::from(),
                CustomFilter::to(),
                CustomFilter::lookup('departmentId', 'Department', 'department'),
                CustomFilter::select('status', 'Status', ['completed' => 'Completed', 'in_progress' => 'In progress']),
            ],
        );
    }
}
