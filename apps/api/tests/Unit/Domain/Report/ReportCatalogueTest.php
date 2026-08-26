<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Report;

use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Authz\SystemRole;
use Central\Api\Domain\Report\ReportCatalogue;
use Central\Api\Domain\Report\ReportCategory;
use Central\Api\Domain\Report\ReportDefinition;
use Central\Api\Domain\Report\ReportKey;
use Central\Api\Domain\Report\ReportScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue, and the permission it carries.
 *
 * The legacy's `audience` field filtered the *listing* and was never consulted
 * when a report ran, so naming a key was enough to run the executive dashboard
 * or the audit trail. These tests pin the two halves together.
 */
#[CoversClass(ReportCatalogue::class)]
#[CoversClass(ReportDefinition::class)]
#[CoversClass(ReportScope::class)]
final class ReportCatalogueTest extends TestCase
{
    private ReportCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->catalogue = new ReportCatalogue();
    }

    #[Test]
    public function every_report_key_has_a_definition(): void
    {
        // A key without one is a route that 500s, and the enum is what every
        // saved report and schedule is checked against.
        foreach (ReportKey::cases() as $key) {
            $definition = $this->catalogue->find($key);

            self::assertSame($key, $definition->key);
            self::assertNotSame('', trim($definition->name));
            self::assertNotSame('', trim($definition->description));
        }
    }

    #[Test]
    public function a_learner_can_run_nothing(): void
    {
        // Reporting is about other people almost by definition. A learner
        // reads their own progress through /me, not through here.
        self::assertSame([], $this->catalogue->availableTo($this->caller()));
    }

    #[Test]
    public function report_view_opens_the_ordinary_reports(): void
    {
        $available = $this->catalogue->availableTo($this->caller(Permission::ReportView));

        self::assertNotSame([], $available);
        self::assertContains(
            ReportKey::OverdueTraining,
            array_map(static fn (ReportDefinition $d): ReportKey => $d->key, $available),
        );
    }

    #[Test]
    public function the_executive_reports_need_more_than_report_view(): void
    {
        $keys = array_map(
            static fn (ReportDefinition $d): ReportKey => $d->key,
            $this->catalogue->availableTo($this->caller(Permission::ReportView)),
        );

        self::assertNotContains(ReportKey::ExecutiveDashboard, $keys);
        self::assertNotContains(ReportKey::YearOverYear, $keys);
    }

    #[Test]
    public function the_proctoring_report_needs_the_proctoring_permission(): void
    {
        // Its rows describe a named person being suspected of cheating, drawn
        // from webcam and screen monitoring. The permission that gates the
        // recordings gates the report about them.
        $definition = $this->catalogue->find(ReportKey::ProctoringViolations);

        self::assertSame(Permission::ProctoringReview, $definition->permission);

        $keys = array_map(
            static fn (ReportDefinition $d): ReportKey => $d->key,
            $this->catalogue->availableTo($this->caller(Permission::ReportView, Permission::ReportExport)),
        );

        self::assertNotContains(ReportKey::ProctoringViolations, $keys);
    }

    #[Test]
    public function filtering_by_category_narrows_the_listing(): void
    {
        $available = $this->catalogue->availableTo(
            $this->caller(Permission::ReportView, Permission::ReportExport),
            ReportCategory::Compliance,
        );

        self::assertNotSame([], $available);

        foreach ($available as $definition) {
            self::assertSame(ReportCategory::Compliance, $definition->category);
        }
    }

    #[Test]
    public function a_report_that_needs_a_learner_says_so(): void
    {
        $definition = $this->catalogue->find(ReportKey::IndividualProgress);

        self::assertSame(['learnerId'], $definition->requires);
        self::assertSame(['learnerId'], $definition->missing([]));
        self::assertSame([], $definition->missing(['learnerId' => 'someone']));
    }

    #[Test]
    public function a_blank_parameter_counts_as_missing(): void
    {
        $definition = $this->catalogue->find(ReportKey::QuestionAnalysis);

        self::assertSame(['assessmentId'], $definition->missing(['assessmentId' => '   ']));
    }

    #[Test]
    public function only_a_table_is_paginated(): void
    {
        // A summary has one row and a breakdown has as many as there are
        // categories; paginating either produces an always-empty second page
        // and a client that renders controls for it.
        foreach ($this->catalogue->all() as $definition) {
            self::assertSame(
                $definition->shape->isPaginated(),
                $definition->toArray()['paginated'],
                $definition->key->value,
            );
        }
    }

    #[Test]
    public function every_exportable_report_has_columns_worth_exporting(): void
    {
        // A summary exports as a key/value sheet, which is fine — but a table
        // marked non-exportable is a table somebody will ask for as a file.
        foreach ($this->catalogue->all() as $definition) {
            if ($definition->shape->isPaginated()) {
                self::assertTrue(
                    $definition->exportable,
                    sprintf('%s is a table but cannot be exported', $definition->key->value),
                );
            }
        }
    }

    // --- scope ------------------------------------------------------------

    #[Test]
    public function report_view_alone_sees_only_its_own_reports(): void
    {
        $scope = ReportScope::forCaller($this->caller(Permission::ReportView), 'tenant-1');

        self::assertFalse($scope->tenantWide);
        self::assertTrue($scope->covers('viewer', null));
        self::assertTrue($scope->covers('their-report', 'viewer'));
        self::assertFalse($scope->covers('somebody-else', 'another-manager'));
    }

    #[Test]
    public function exporting_is_granted_on_top_of_viewing_never_instead_of_it(): void
    {
        // The catalogue asks for the exact permission a report declares, so a
        // role holding only `report.export` would see the executive reports
        // and none of the ordinary ones. Every role that grants it inherits
        // `report.view` too, and this is where that assumption is written
        // down.
        foreach (SystemRole::cases() as $role) {
            $permissions = $role->effectivePermissions();

            if (in_array(Permission::ReportExport, $permissions, true)) {
                self::assertContains(
                    Permission::ReportView,
                    $permissions,
                    $role->value . ' can export reports but not view them',
                );
            }
        }
    }

    #[Test]
    public function report_export_sees_the_whole_organisation(): void
    {
        $scope = ReportScope::forCaller($this->caller(Permission::ReportExport), 'tenant-1');

        self::assertTrue($scope->tenantWide);
        self::assertTrue($scope->covers('somebody-else', 'another-manager'));
        self::assertTrue($scope->isUnrestricted());
    }

    #[Test]
    public function managing_people_also_widens_the_scope(): void
    {
        // Somebody administering the people records already sees every row
        // this would show; refusing them the report is theatre.
        self::assertTrue(ReportScope::forCaller($this->caller(Permission::UserManage), 't')->tenantWide);
        self::assertTrue(ReportScope::forCaller($this->caller(Permission::EnrollmentManage), 't')->tenantWide);
    }

    #[Test]
    public function narrowing_to_a_subject_stops_it_being_unrestricted(): void
    {
        $scope = ReportScope::forCaller($this->caller(Permission::ReportExport), 'tenant-1')
            ->narrowedTo('one-learner');

        self::assertSame('one-learner', $scope->subjectId);
        self::assertFalse($scope->isUnrestricted());
    }

    private function caller(Permission ...$permissions): AuthenticatedUser
    {
        return new AuthenticatedUser(
            'viewer',
            'tenant-1',
            'viewer@example.test',
            [new Role('role-1', 'tenant-1', 'tester', 'Tester', array_values($permissions))],
        );
    }
}
