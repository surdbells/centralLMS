<?php

declare(strict_types=1);

namespace Central\Api\Domain\Authz;

/**
 * The complete set of things a caller can be allowed to do.
 *
 * This enum is the single source of truth. Route guards, the JWT payload, the
 * admin UI's permission picker and the seed migrations all derive from it — if
 * a permission is not listed here it does not exist. Adding a case is a
 * deliberate act that shows up in review; scattering ad-hoc `if ($role ===
 * 'admin')` checks through controllers, as the legacy backend did, is not.
 */
enum Permission: string
{
    // --- catalogue --------------------------------------------------------
    case CourseView    = 'course.view';
    case CourseManage  = 'course.manage';
    case CoursePublish = 'course.publish';

    // --- learning ---------------------------------------------------------
    case EnrollmentSelf   = 'enrollment.self';
    case EnrollmentManage = 'enrollment.manage';
    case ProgressView     = 'progress.view';
    case ProgressManage   = 'progress.manage';

    // --- instructor-led training -----------------------------------------
    case IltSessionView   = 'ilt.session.view';
    case IltSessionManage = 'ilt.session.manage';
    case IltAttendanceMark = 'ilt.attendance.mark';

    // --- assessment -------------------------------------------------------
    // Sitting one. Held by learners; authoring and marking are not.
    case AssessmentTake    = 'assessment.take';
    case AssessmentManage  = 'assessment.manage';
    case AssessmentGrade   = 'assessment.grade';
    case CertificateIssue  = 'certificate.issue';

    // --- proctoring (advanced module) ------------------------------------
    // Separate from AssessmentManage on purpose: configuring proctoring and
    // reviewing violations means access to webcam and screen recordings of
    // learners. A course author who may write quizzes must not get that by
    // default. See docs/migration/schema-assessments.md.
    case ProctoringConfigure = 'proctoring.configure';
    case ProctoringReview    = 'proctoring.review';

    // --- organisation settings --------------------------------------------
    // Distinct from `tenant.manage`, which is the platform's control over a
    // tenant record — provisioning it, renaming it, suspending it. This one is
    // an organisation configuring itself: its colours, what a lesson is worth,
    // what a password must look like. An org admin holds it; a tenant admin
    // holds it by inheritance.
    case SettingsManage = 'settings.manage';

    // --- media library ----------------------------------------------------
    // Viewing is separate from managing because a learner has to be able to
    // play the video a lesson embeds, and must not be able to replace it.
    case MediaView   = 'media.view';
    case MediaManage = 'media.manage';

    // --- reference data (branches, departments, teams, levels, …) ---------
    case LookupView   = 'lookup.view';
    case LookupManage = 'lookup.manage';

    // --- people -----------------------------------------------------------
    case UserView   = 'user.view';
    case UserManage = 'user.manage';
    case RoleManage = 'role.manage';

    // --- tenancy / back-office (apps/saas) --------------------------------
    case TenantView     = 'tenant.view';
    case TenantManage   = 'tenant.manage';
    case BillingManage  = 'billing.manage';
    case IntegrationManage = 'integration.manage';

    // --- notifications ----------------------------------------------------
    // Reading your own inbox needs no permission — everyone has one. These
    // two are for acting on other people: sending an announcement to the
    // whole organisation, and reading the delivery log, which lists the
    // titles of notifications addressed to everybody else.
    case NotificationSend  = 'notification.send';
    case NotificationAudit = 'notification.audit';
    // --- reporting --------------------------------------------------------
    case ReportView   = 'report.view';
    case ReportExport = 'report.export';
    case AuditLogView = 'audit.view';

    /**
     * The module this permission belongs to, taken from its own name.
     *
     * Derived rather than listed: a second list would be one more thing to
     * forget when a case is added.
     */
    public function group(): string
    {
        return explode(".", $this->value)[0];
    }

    /**
     * What ticking this box lets somebody do, in a sentence.
     *
     * An exhaustive match with no default, so adding a case without saying
     * what it means is a static analysis failure rather than a blank row in
     * the role editor.
     */
    public function describe(): string
    {
        return match ($this) {
            self::CourseView    => "See the course catalogue.",
            self::CourseManage  => "Create and edit courses, modules and lessons.",
            self::CoursePublish => "Publish a course, making it visible to learners.",

            self::EnrollmentSelf   => "Enrol themselves on courses that allow it.",
            self::EnrollmentManage => "Enrol and unenrol other people.",
            self::ProgressView     => "See other people's progress.",
            self::ProgressManage   => "Change other people's progress and completion.",

            self::IltSessionView    => "See scheduled classroom and virtual sessions.",
            self::IltSessionManage  => "Schedule, edit and cancel sessions.",
            self::IltAttendanceMark => "Mark who attended a session.",

            self::AssessmentTake   => "Sit assessments.",
            self::AssessmentManage => "Author assessments and question banks.",
            self::AssessmentGrade  => "Mark submitted answers.",
            self::CertificateIssue => "Issue and revoke certificates.",

            self::ProctoringConfigure => "Configure proctoring, including webcam and screen capture.",
            self::ProctoringReview    => "Review proctoring recordings and violations.",

            self::SettingsManage => "Change this organisation's settings and branding.",

            self::MediaView   => "View and play files in the media library.",
            self::MediaManage => "Upload, replace and delete media.",

            self::LookupView   => "See reference data: branches, departments, teams, skills.",
            self::LookupManage => "Edit reference data.",

            self::UserView   => "See the people in this organisation.",
            self::UserManage => "Create, edit, suspend and delete people.",
            self::RoleManage => "Create roles and decide what they may do.",

            self::TenantView       => "See tenant records in the back office.",
            self::TenantManage     => "Provision, rename and suspend tenants.",
            self::BillingManage    => "Manage subscriptions and invoices.",
            self::IntegrationManage => "Configure third-party integrations.",

            self::NotificationSend  => "Send announcements to other people.",
            self::NotificationAudit => "Read the notification delivery log.",

            self::ReportView   => "Run reports.",
            self::ReportExport => "Export report results.",
            self::AuditLogView => "Read the audit trail.",
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    public static function tryFromValue(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
