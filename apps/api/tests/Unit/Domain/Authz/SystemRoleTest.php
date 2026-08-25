<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Authz;

use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Authz\SystemRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemRole::class)]
#[CoversClass(Role::class)]
final class SystemRoleTest extends TestCase
{
    #[Test]
    public function super_admin_holds_every_permission(): void
    {
        // Asserted against the enum rather than a hard-coded list, so adding a
        // permission can never silently leave super admin without it.
        self::assertEqualsCanonicalizing(
            Permission::cases(),
            SystemRole::SuperAdmin->effectivePermissions(),
        );
    }

    #[Test]
    public function each_role_inherits_everything_its_parent_can_do(): void
    {
        foreach (SystemRole::cases() as $role) {
            foreach ($role->inherits() as $parent) {
                foreach ($parent->effectivePermissions() as $inherited) {
                    self::assertContains(
                        $inherited,
                        $role->effectivePermissions(),
                        sprintf('%s should inherit %s from %s', $role->value, $inherited->value, $parent->value),
                    );
                }
            }
        }
    }

    #[Test]
    public function tenant_admin_already_holds_every_permission(): void
    {
        // Documented on purpose: within their own tenant a tenant admin can do
        // everything, so super admin is NOT distinguished by extra permissions
        // — it is distinguished by reaching across tenants, enforced separately
        // by AuthenticatedUser::canAccessTenant().
        self::assertEqualsCanonicalizing(
            Permission::cases(),
            SystemRole::TenantAdmin->effectivePermissions(),
        );
    }

    #[Test]
    public function a_learner_cannot_manage_anything(): void
    {
        $learner = SystemRole::Learner->effectivePermissions();

        $managementPermissions = [
            Permission::CourseManage,
            Permission::UserManage,
            Permission::TenantManage,
            Permission::BillingManage,
            Permission::IltSessionManage,
            Permission::ProctoringReview,
        ];

        foreach ($managementPermissions as $forbidden) {
            self::assertNotContains($forbidden, $learner);
        }
    }

    #[Test]
    public function effective_permissions_contain_no_duplicates(): void
    {
        foreach (SystemRole::cases() as $role) {
            $permissions = $role->effectivePermissions();

            self::assertSame(
                count($permissions),
                count(array_unique(array_map(static fn (Permission $p): string => $p->value, $permissions))),
                sprintf('%s has duplicate permissions', $role->value),
            );
        }
    }

    #[Test]
    public function built_in_roles_materialise_as_system_roles_available_to_every_tenant(): void
    {
        foreach (SystemRole::cases() as $case) {
            $role = $case->toRole();

            self::assertTrue($role->isSystem);
            self::assertNull($role->tenantId);
            self::assertSame($case, $role->systemRole());
            // Built-ins exist in every tenant, including for a caller with none.
            self::assertTrue($role->isAvailableTo('any-tenant'));
            self::assertTrue($role->isAvailableTo(null));
            // A tenant admin must not be able to redefine them.
            self::assertFalse($role->isEditable());
        }
    }

    #[Test]
    public function a_custom_role_belongs_to_exactly_one_tenant(): void
    {
        $role = Role::custom(
            id: 'role-1',
            tenantId: 'tenant-a',
            slug: 'course-reviewer',
            name: 'Course Reviewer',
            permissions: [Permission::CourseView, Permission::ReportView],
        );

        self::assertTrue($role->isAvailableTo('tenant-a'));
        self::assertFalse($role->isAvailableTo('tenant-b'));
        self::assertFalse($role->isAvailableTo(null));
        self::assertTrue($role->isEditable());
        self::assertNull($role->systemRole());
    }

    #[Test]
    public function a_custom_role_accepts_permission_strings_and_deduplicates(): void
    {
        $role = Role::custom(
            id: 'role-2',
            tenantId: 'tenant-a',
            slug: 'reviewer',
            name: 'Reviewer',
            permissions: ['course.view', Permission::CourseView, 'report.view'],
        );

        self::assertCount(2, $role->permissions);
        self::assertTrue($role->has(Permission::CourseView));
        self::assertTrue($role->has(Permission::ReportView));
    }

    #[Test]
    public function a_tenant_cannot_invent_a_permission(): void
    {
        // Roles are tenant-editable data; the permission list is code. Without
        // this check a tenant could grant themselves anything by typing it.
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessageMatches('/Unknown permission/');

        Role::custom(
            id: 'role-3',
            tenantId: 'tenant-a',
            slug: 'god-mode',
            name: 'God Mode',
            permissions: ['tenant.delete_everything'],
        );
    }
}
