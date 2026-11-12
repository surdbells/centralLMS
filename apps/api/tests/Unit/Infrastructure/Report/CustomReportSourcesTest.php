<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Infrastructure\Report;

use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Report\CustomFilter;
use Central\Api\Domain\Report\CustomReportSpec;
use Central\Api\Domain\Report\CustomSource;
use Central\Api\Domain\Report\ReportCriteria;
use Central\Api\Domain\Report\ReportScope;
use Central\Api\Infrastructure\Persistence\CustomReportSources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The custom report builder's data sources and the SQL they produce.
 *
 * The first test is the one the builder's empty "Data Source" dropdown was
 * missing: there are sources, and each one is complete enough to build from.
 */
#[CoversClass(CustomReportSources::class)]
final class CustomReportSourcesTest extends TestCase
{
    private const TENANT = '11111111-1111-4111-8111-111111111111';

    #[Test]
    public function the_builder_has_sources_to_offer(): void
    {
        $keys = array_map(static fn (CustomSource $s): string => $s->key, (new CustomReportSources())->sources());

        self::assertSame(
            ['enrollments', 'compliance', 'learners', 'courses', 'assessments', 'certificates'],
            $keys,
        );
    }

    #[Test]
    #[DataProvider('sources')]
    public function every_source_is_complete_and_consistent(string $key): void
    {
        $source = (new CustomReportSources())->source($key);

        self::assertNotNull($source);
        self::assertNotSame('', $source->label);
        self::assertNotSame([], $source->fields);
        self::assertNotSame([], $source->groupings);

        $fieldKeys = array_map(static fn ($f): string => $f->key, $source->fields);
        self::assertSame(array_unique($fieldKeys), $fieldKeys, 'Field keys are unique.');

        foreach ($source->defaultColumns as $column) {
            self::assertNotNull($source->field($column), sprintf('Default column "%s" exists.', $column));
        }

        foreach (array_keys($source->groupings) as $grouping) {
            // A grouping that shares a key with a summarised field would give
            // a grouped result two columns of the same name.
            self::assertFalse(
                $source->field($grouping)?->isAggregatable() ?? false,
                sprintf('Grouping "%s" does not collide with a summarised field.', $grouping),
            );
        }

        foreach ($source->filters as $filter) {
            if ($filter->type === CustomFilter::SELECT) {
                self::assertNotSame([], $filter->options);
            }

            if ($filter->type === CustomFilter::LOOKUP) {
                self::assertNotNull($filter->lookupKind);
            }
        }

        $schema = $source->schema();
        self::assertSame($key, $schema['key']);
        self::assertCount(count($source->fields), $schema['columns']);
    }

    #[Test]
    #[DataProvider('sources')]
    public function every_column_grouping_and_filter_produces_a_statement(string $key): void
    {
        $catalogue = new CustomReportSources();
        $source    = $catalogue->source($key);
        self::assertNotNull($source);

        $allColumns = array_map(static fn ($f): string => $f->key, $source->fields);

        $filters = [];

        foreach ($source->filters as $filter) {
            $filters[$filter->key] = match ($filter->type) {
                CustomFilter::DATE   => '2026-01-01',
                CustomFilter::SELECT => $filter->options[0]['value'],
                default              => '22222222-2222-4222-8222-222222222222',
            };
        }

        // Ungrouped, every column, every filter, sorted by each column.
        foreach ($allColumns as $sortBy) {
            $statement = $catalogue->statement(
                $this->spec($source, ['columns' => $allColumns, 'sortBy' => $sortBy, 'filters' => $filters]),
                $this->criteria(),
            );

            self::assertStringContainsString('tenant_id = :tenantId', $statement['rows']);
            self::assertStringContainsString('LIMIT :limit OFFSET :offset', $statement['rows']);
            self::assertSame(self::TENANT, $statement['params']['tenantId']);

            foreach ($source->filters as $filter) {
                if (!$filter->isDate()) {
                    self::assertArrayHasKey('f_' . $filter->key, $statement['params']);
                    self::assertStringContainsString(':f_' . $filter->key, $statement['rows']);
                }
            }
        }

        // Grouped by each grouping.
        foreach (array_keys($source->groupings) as $grouping) {
            $statement = $catalogue->statement(
                $this->spec($source, ['columns' => $allColumns, 'groupBy' => $grouping]),
                $this->criteria(),
            );

            self::assertStringContainsString('GROUP BY 1', $statement['rows']);
            self::assertStringContainsString(sprintf('AS "%s"', $grouping), $statement['rows']);
            self::assertStringContainsString('COUNT(*) AS "records"', $statement['rows']);
            self::assertStringContainsString('custom_groups', $statement['count']);
        }
    }

    #[Test]
    public function a_caller_without_organisation_wide_rights_sees_only_their_people(): void
    {
        $catalogue = new CustomReportSources();
        $source    = $catalogue->source('enrollments');
        self::assertNotNull($source);

        $statement = $catalogue->statement(
            $this->spec($source, ['columns' => ['learner']]),
            $this->criteria([Permission::ReportView]),
        );

        self::assertStringContainsString('(u.id = :viewerId OR u.manager_id = :viewerId)', $statement['rows']);
        self::assertStringContainsString('(u.id = :viewerId OR u.manager_id = :viewerId)', $statement['count']);
        self::assertSame('viewer', $statement['params']['viewerId']);
    }

    #[Test]
    public function values_are_bound_never_interpolated(): void
    {
        $catalogue = new CustomReportSources();
        $source    = $catalogue->source('enrollments');
        self::assertNotNull($source);

        $id        = '33333333-3333-4333-8333-333333333333';
        $statement = $catalogue->statement(
            $this->spec($source, ['columns' => ['learner'], 'filters' => ['courseId' => $id]]),
            $this->criteria(),
        );

        self::assertStringNotContainsString($id, $statement['rows']);
        self::assertSame($id, $statement['params']['f_courseId']);
    }

    #[Test]
    public function the_default_sort_works_when_its_column_is_not_shown(): void
    {
        $catalogue = new CustomReportSources();
        $source    = $catalogue->source('enrollments');
        self::assertNotNull($source);

        $statement = $catalogue->statement(
            $this->spec($source, ['columns' => ['learner']]),
            $this->criteria(),
        );

        // Ordered by the expression, not by an alias that is not selected.
        self::assertStringContainsString('ORDER BY e.enrolled_at DESC NULLS LAST, e.id', $statement['rows']);
    }

    #[Test]
    public function the_count_query_does_not_carry_the_page_parameters_in_its_sql(): void
    {
        $catalogue = new CustomReportSources();
        $source    = $catalogue->source('learners');
        self::assertNotNull($source);

        $statement = $catalogue->statement($this->spec($source, ['columns' => ['learner']]), $this->criteria());

        self::assertStringNotContainsString(':limit', $statement['count']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sources(): iterable
    {
        foreach ((new CustomReportSources())->sources() as $source) {
            yield $source->key => [$source->key];
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function spec(CustomSource $source, array $input): CustomReportSpec
    {
        $violations = [];
        $spec       = CustomReportSpec::fromInput($source, $input, $violations);

        self::assertSame([], $violations);
        self::assertNotNull($spec);

        return $spec;
    }

    /**
     * @param list<Permission> $permissions
     */
    private function criteria(array $permissions = [Permission::ReportView, Permission::ReportExport]): ReportCriteria
    {
        $scope = ReportScope::forCaller(
            new AuthenticatedUser(
                'viewer',
                self::TENANT,
                'viewer@example.test',
                [new Role('r', self::TENANT, 'tester', 'Tester', $permissions)],
            ),
            self::TENANT,
        );

        $violations = [];
        $criteria   = ReportCriteria::fromInput($scope, ['from' => '2026-01-01', 'to' => '2026-12-31'], $violations);

        self::assertNotNull($criteria);

        return $criteria;
    }
}
