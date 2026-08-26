<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Report;

use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Report\ReportCriteria;
use Central\Api\Domain\Report\ReportScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a caller may ask for, and what they may not.
 *
 * The first test is the important one: there is no way to put a tenant into
 * these criteria. That is the structural half of the fix for the live defect
 * where `api/reports.php` reads `tenant_id` from a request body.
 */
#[CoversClass(ReportCriteria::class)]
final class ReportCriteriaTest extends TestCase
{
    #[Test]
    public function a_tenant_in_the_request_body_is_ignored(): void
    {
        // The whole point. Whatever a caller sends, the tenant comes from the
        // scope, which comes from the token.
        $violations = [];

        $criteria = ReportCriteria::fromInput(
            $this->scope('the-real-tenant'),
            ['tenantId' => 'somebody-elses-tenant', 'tenant_id' => 'also-not-mine'],
            $violations,
        );

        self::assertNotNull($criteria);
        self::assertSame('the-real-tenant', $criteria->scope->tenantId);
        self::assertArrayNotHasKey('tenantId', $criteria->toArray());
    }

    #[Test]
    public function a_saved_criteria_set_carries_no_tenant(): void
    {
        // Saved reports store this array. If a tenant could get in, sharing
        // one would hand a colleague another organisation's rows.
        $violations = [];

        $criteria = ReportCriteria::fromInput(
            $this->scope(),
            ['courseId' => '11111111-1111-4111-8111-111111111111', 'from' => '2026-01-01'],
            $violations,
        );

        self::assertNotNull($criteria);

        foreach (array_keys($criteria->toArray()) as $key) {
            self::assertStringNotContainsStringIgnoringCase('tenant', $key);
        }
    }

    #[Test]
    public function the_end_of_a_range_includes_the_whole_day(): void
    {
        // Without this, "to: 2026-03-31" silently excludes everything that
        // happened on the 31st, and a monthly report is short by a day.
        $violations = [];

        $criteria = ReportCriteria::fromInput(
            $this->scope(),
            ['from' => '2026-03-01', 'to' => '2026-03-31'],
            $violations,
        );

        self::assertNotNull($criteria);
        self::assertSame('2026-03-31 23:59:59', $criteria->to?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-01 00:00:00', $criteria->from?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_range_that_runs_backwards_is_refused(): void
    {
        // It returns nothing, which looks like missing data rather than a
        // mistake in the question.
        $violations = [];

        $criteria = ReportCriteria::fromInput(
            $this->scope(),
            ['from' => '2026-03-31', 'to' => '2026-03-01'],
            $violations,
        );

        self::assertNull($criteria);
        self::assertArrayHasKey('from', $violations);
    }

    #[Test]
    public function an_unparseable_date_is_refused(): void
    {
        $violations = [];

        self::assertNull(ReportCriteria::fromInput($this->scope(), ['from' => 'last Tuesdayish'], $violations));
        self::assertArrayHasKey('from', $violations);
    }

    #[Test]
    public function an_absurd_page_size_is_refused(): void
    {
        $violations = [];

        self::assertNull(ReportCriteria::fromInput($this->scope(), ['perPage' => 100000], $violations));
        self::assertArrayHasKey('perPage', $violations);

        $violations = [];
        self::assertNull(ReportCriteria::fromInput($this->scope(), ['perPage' => 0], $violations));
    }

    #[Test]
    public function an_unknown_sort_direction_is_refused(): void
    {
        $violations = [];

        self::assertNull(ReportCriteria::fromInput($this->scope(), ['sortDirection' => 'sideways'], $violations));
        self::assertArrayHasKey('sortDirection', $violations);
    }

    #[Test]
    public function an_id_that_is_not_an_id_is_dropped_rather_than_bound(): void
    {
        // The query would find nothing for a bad id anyway; this keeps
        // obvious rubbish out of a bound parameter.
        $violations = [];

        $criteria = ReportCriteria::fromInput(
            $this->scope(),
            ['courseId' => "'; DROP TABLE courses; --"],
            $violations,
        );

        self::assertNotNull($criteria);
        self::assertNull($criteria->courseId);
    }

    #[Test]
    public function a_sort_column_the_report_does_not_offer_falls_back(): void
    {
        // A column name cannot be interpolated into SQL from a request body,
        // so the choice is matched against a list the report declares. A
        // stale bookmark should still show the report.
        $violations = [];

        $criteria = ReportCriteria::fromInput($this->scope(), ['sortBy' => 'salary'], $violations);

        self::assertNotNull($criteria);
        self::assertSame('enrolledAt', $criteria->sortColumn(['learner', 'enrolledAt'], 'enrolledAt'));
        self::assertSame('learner', $criteria->onPage(1)->sortColumn(['learner'], 'learner'));
    }

    #[Test]
    public function a_sort_column_the_report_does_offer_is_used(): void
    {
        $violations = [];

        $criteria = ReportCriteria::fromInput($this->scope(), ['sortBy' => 'score'], $violations);

        self::assertNotNull($criteria);
        self::assertSame('score', $criteria->sortColumn(['score', 'learner'], 'learner'));
    }

    #[Test]
    public function paging_arithmetic_is_one_based(): void
    {
        $violations = [];

        $criteria = ReportCriteria::fromInput($this->scope(), ['page' => 3, 'perPage' => 25], $violations);

        self::assertNotNull($criteria);
        self::assertSame(50, $criteria->offset());
        self::assertSame(0, $criteria->onPage(1)->offset());
        // Page nought is page one, not a negative offset.
        self::assertSame(0, $criteria->onPage(0)->offset());
    }

    #[Test]
    public function an_export_is_still_capped(): void
    {
        // Streaming a million rows through PHP is a way to take the API down
        // and produce a spreadsheet nobody can open.
        $violations = [];

        $criteria = ReportCriteria::fromInput($this->scope(), ['perPage' => 10], $violations);

        self::assertNotNull($criteria);
        self::assertSame(500, $criteria->forExport()->perPage);
        self::assertSame(1, $criteria->forExport()->page);
    }

    #[Test]
    public function filters_keep_only_scalar_values(): void
    {
        $violations = [];

        $criteria = ReportCriteria::fromInput(
            $this->scope(),
            ['filters' => ['inactiveDays' => 30, 'nested' => ['not' => 'allowed']]],
            $violations,
        );

        self::assertNotNull($criteria);
        self::assertSame(['inactiveDays' => '30'], $criteria->filters);
    }

    private function scope(string $tenantId = 'tenant-1'): ReportScope
    {
        return ReportScope::forCaller(
            new AuthenticatedUser(
                'viewer',
                $tenantId,
                'viewer@example.test',
                [new Role('r', $tenantId, 'tester', 'Tester', [Permission::ReportExport])],
            ),
            $tenantId,
        );
    }
}
