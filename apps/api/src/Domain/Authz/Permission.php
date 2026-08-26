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

    // --- reporting --------------------------------------------------------
    case ReportView   = 'report.view';
    case ReportExport = 'report.export';
    case AuditLogView = 'audit.view';

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
