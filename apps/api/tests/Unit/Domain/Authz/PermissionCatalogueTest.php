<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Authz;

use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\PermissionCatalogue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PermissionCatalogue::class)]
#[CoversClass(Permission::class)]
final class PermissionCatalogueTest extends TestCase
{
    #[Test]
    public function the_catalogue_lists_every_permission_exactly_once(): void
    {
        // The legacy kept a `permissions` table beside the checks in code; the
        // two drifted, so the role editor offered boxes that meant nothing and
        // the code demanded permissions no administrator had ever seen. The
        // catalogue is now the enum, and this says so.
        $listed = [];

        foreach (PermissionCatalogue::grouped() as $group) {
            foreach ($group['permissions'] as $permission) {
                $listed[] = $permission['value'];
            }
        }

        self::assertEqualsCanonicalizing(Permission::values(), $listed);
        self::assertSame(count($listed), count(array_unique($listed)));
    }

    #[Test]
    public function every_permission_says_what_it_allows(): void
    {
        // `describe()` is an exhaustive match, so a new case without a
        // sentence fails here rather than rendering a blank row.
        foreach (Permission::cases() as $permission) {
            self::assertNotSame('', trim($permission->describe()), $permission->value);
        }
    }

    #[Test]
    public function a_permission_is_filed_under_the_module_its_name_names(): void
    {
        self::assertSame('course', Permission::CoursePublish->group());
        self::assertSame('ilt', Permission::IltAttendanceMark->group());
        self::assertSame('role', Permission::RoleManage->group());
    }

    #[Test]
    public function groups_and_permissions_are_both_labelled(): void
    {
        foreach (PermissionCatalogue::grouped() as $group) {
            self::assertNotSame('', trim($group['label']), $group['module']);

            foreach ($group['permissions'] as $permission) {
                self::assertNotSame('', trim($permission['label']), $permission['value']);
            }
        }
    }

    #[Test]
    public function modules_that_do_not_read_as_words_are_given_names(): void
    {
        $labels = [];

        foreach (PermissionCatalogue::grouped() as $group) {
            $labels[$group['module']] = $group['label'];
        }

        self::assertSame('Instructor-led training', $labels['ilt']);
        self::assertSame('Reference data', $labels['lookup']);
        self::assertSame('People', $labels['user']);
        // And one that needs nothing said about it.
        self::assertSame('Course', $labels['course']);
    }

    #[Test]
    public function the_action_label_drops_the_module_it_is_already_filed_under(): void
    {
        $ilt = [];

        foreach (PermissionCatalogue::grouped() as $group) {
            if ($group['module'] === 'ilt') {
                $ilt = array_column($group['permissions'], 'label', 'value');
            }
        }

        self::assertSame('Attendance mark', $ilt['ilt.attendance.mark']);
        self::assertSame('Session view', $ilt['ilt.session.view']);
    }
}
