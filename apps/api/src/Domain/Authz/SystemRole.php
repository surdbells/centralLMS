<?php

declare(strict_types=1);

namespace Central\Api\Domain\Authz;

/**
 * The roles the platform ships with.
 *
 * Tenants can define their own roles, so a role is *data* — see {@see Role}.
 * These five are the exception: they exist in every tenant, they are what a
 * new tenant is seeded with, and code may reference them by name (a super
 * admin's cross-tenant reach is a rule, not a permission grant).
 *
 * A tenant may not rename or delete these, but may create any number of its
 * own alongside them.
 */
enum SystemRole: string
{
    // The ladder: each inherits the one before it.
    case Learner     = 'learner';
    case Instructor  = 'instructor';
    case OrgAdmin    = 'org_admin';
    case TenantAdmin = 'tenant_admin';
    case SuperAdmin  = 'super_admin';

    // Lateral roles. These are not rungs on the ladder — a content creator
    // is not "more" than an instructor, just different. They inherit Learner
    // and nothing else. Ported from the legacy per-tenant role seed, which
    // had eight roles where this enum originally had five.
    case ContentCreator = 'content_creator';
    case HrManager      = 'hr_manager';
    case Hr             = 'hr';
    case Manager        = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Learner     => 'Learner',
            self::Instructor  => 'Instructor',
            self::OrgAdmin    => 'Organisation Admin',
            self::TenantAdmin => 'Tenant Admin',
            self::SuperAdmin  => 'Super Admin',
            self::ContentCreator => 'Content Creator',
            self::HrManager      => 'HR Manager',
            self::Hr             => 'HR Staff',
            self::Manager        => 'Team Manager',
        };
    }

    /**
     * Permissions granted directly by this role, before inheritance.
     *
     * @return list<Permission>
     */
    public function grants(): array
    {
        return match ($this) {
            self::Learner => [
                // Everyone needs to read reference data — it fills the dropdowns
                // on their own profile and on enrolment forms.
                Permission::LookupView,
                Permission::CourseView,
                Permission::EnrollmentSelf,
                Permission::AssessmentTake,
                Permission::ProgressView,
                Permission::IltSessionView,
            ],
            self::Instructor => [
                Permission::CourseManage,
                Permission::ProgressManage,
                Permission::IltSessionManage,
                Permission::IltAttendanceMark,
                Permission::AssessmentManage,
                Permission::AssessmentGrade,
                Permission::UserView,
                Permission::ReportView,
            ],
            self::OrgAdmin => [
                Permission::LookupManage,
                Permission::CoursePublish,
                Permission::EnrollmentManage,
                Permission::CertificateIssue,
                Permission::UserManage,
                Permission::ReportExport,
                // Proctoring recordings are personal data; org admin is the
                // lowest role trusted with them.
                Permission::ProctoringConfigure,
                Permission::ProctoringReview,
            ],
            self::TenantAdmin => [
                Permission::RoleManage,
                Permission::TenantView,
                Permission::TenantManage,
                Permission::BillingManage,
                Permission::IntegrationManage,
                Permission::AuditLogView,
            ],
            // "Content management — create and edit courses".
            self::ContentCreator => [
                Permission::CourseManage,
                Permission::AssessmentManage,
            ],
            // "HR operations — view users, compliance reports".
            self::Hr => [
                Permission::UserView,
                Permission::ReportView,
            ],
            // "HR management — users, compliance, reports".
            self::HrManager => [
                Permission::UserManage,
                Permission::EnrollmentManage,
                Permission::ReportExport,
            ],
            // "Team management — direct reports, team reports".
            self::Manager => [
                Permission::UserView,
                Permission::ProgressView,
                Permission::ReportView,
            ],
            // Super admin takes everything through the wildcard in
            // effectivePermissions(); listing cases here would go stale each
            // time a permission is added.
            self::SuperAdmin => [],
        };
    }

    /**
     * Roles whose permissions this role also receives.
     *
     * Inheritance applies only to the built-in ladder. Tenant-defined roles
     * are flat: a custom role holds exactly the permissions assigned to it,
     * because an inheritance graph that tenants can edit is a graph that will
     * eventually contain a cycle.
     *
     * @return list<self>
     */
    public function inherits(): array
    {
        return match ($this) {
            self::Learner     => [],
            self::Instructor  => [self::Learner],
            self::OrgAdmin    => [self::Instructor],
            self::TenantAdmin => [self::OrgAdmin],
            self::SuperAdmin  => [self::TenantAdmin],
            // Lateral roles sit beside the ladder, not on it.
            self::ContentCreator, self::Hr, self::Manager => [self::Learner],
            self::HrManager => [self::Hr],
        };
    }

    /**
     * Every permission this role ends up with, inheritance resolved.
     *
     * @return list<Permission>
     */
    public function effectivePermissions(): array
    {
        if ($this === self::SuperAdmin) {
            return Permission::cases();
        }

        $permissions = $this->grants();

        foreach ($this->inherits() as $parent) {
            $permissions = [...$permissions, ...$parent->effectivePermissions()];
        }

        return array_values(array_unique($permissions, SORT_REGULAR));
    }

    /**
     * Materialises this built-in as a {@see Role}, so callers never need to
     * care whether a role is built-in or tenant-defined.
     */
    public function toRole(): Role
    {
        return new Role(
            id: $this->value,
            tenantId: null,
            slug: $this->value,
            name: $this->label(),
            permissions: $this->effectivePermissions(),
            isSystem: true,
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
