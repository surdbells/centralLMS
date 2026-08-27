<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * Every kind of notification the system can raise.
 *
 * Closed on purpose. In the legacy `notifications.type` was a `varchar(50)`
 * and ten rows managed ten distinct spellings, two of which meant the same
 * thing (`course_completion` and `course_completed`). A free-text type is
 * unfilterable, so a preference screen cannot be built on it — which is
 * presumably why there wasn't one.
 *
 * A type carries its own policy: which channels it goes out on by default,
 * how loud it is, and whether someone is allowed to turn it off. Putting that
 * here rather than in a config file means adding a type without deciding those
 * things does not compile.
 */
enum NotificationType: string
{
    // --- account ----------------------------------------------------------
    case Welcome = 'welcome';
    case Invitation = 'invitation';
    case PasswordReset = 'password_reset';

    // --- enrolment --------------------------------------------------------
    case EnrollmentAssigned = 'enrollment_assigned';
    case EnrollmentDueSoon  = 'enrollment_due_soon';
    case EnrollmentOverdue  = 'enrollment_overdue';
    case CourseCompleted    = 'course_completed';

    // --- assessment -------------------------------------------------------
    case AssessmentGraded = 'assessment_graded';

    // --- certificates -----------------------------------------------------
    case CertificateIssued  = 'certificate_issued';
    case CertificateRevoked = 'certificate_revoked';

    // --- instructor-led training -----------------------------------------
    case SessionScheduled   = 'session_scheduled';
    case SessionReminder    = 'session_reminder';
    case SessionRescheduled = 'session_rescheduled';
    case SessionCancelled   = 'session_cancelled';

    // --- from a person, not an event -------------------------------------
    case Announcement = 'announcement';

    /**
     * Where it goes when the recipient has expressed no preference.
     *
     * SMS is in no default. It costs money per message and the tenant is the
     * one paying: opting *in* to it is a decision someone should make on
     * purpose, and defaulting a whole tenant into per-message billing because
     * a column said `{email,sms}` is not a decision anyone made.
     *
     * @return list<NotificationChannel>
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            // Time-critical and about a commitment already in the diary.
            self::SessionReminder,
            self::SessionRescheduled,
            self::SessionCancelled => [
                NotificationChannel::InApp,
                NotificationChannel::Email,
                NotificationChannel::Push,
            ],

            // Worth an email: they are being asked to do something.
            self::EnrollmentAssigned,
            self::EnrollmentDueSoon,
            self::EnrollmentOverdue,
            self::SessionScheduled,
            self::CertificateIssued,
            self::CertificateRevoked,
            self::Announcement => [
                NotificationChannel::InApp,
                NotificationChannel::Email,
            ],

            // Pleasant to see on the bell; an email about it is noise.
            self::Welcome,
            self::CourseCompleted,
            self::AssessmentGraded => [NotificationChannel::InApp],

            // Email and nothing else. Both carry a link that sets a
            // password, and the in-app copy would leave that link sitting
            // on the bell of the very account it opens. One place only.
            // The invitation has a second reason: until it is redeemed the
            // account cannot be signed into, so nobody could read it.
            self::Invitation,
            self::PasswordReset => [NotificationChannel::Email],
        };
    }

    public function priority(): NotificationPriority
    {
        return match ($this) {
            self::SessionCancelled,
            self::SessionRescheduled,
            self::CertificateRevoked => NotificationPriority::Urgent,

            self::SessionReminder,
            self::EnrollmentOverdue,
            self::PasswordReset      => NotificationPriority::High,

            self::Welcome,
            self::CourseCompleted    => NotificationPriority::Low,

            default                  => NotificationPriority::Normal,
        };
    }

    /**
     * Whether the recipient may turn this off.
     *
     * Four types may not. Each one tells somebody that a commitment they
     * already have has changed underneath them: a learner who muted
     * `session_cancelled` travels to a room nobody is in, and one who muted
     * `certificate_revoked` keeps presenting a credential that no longer
     * verifies. A preference screen that offers to switch those off is
     * offering to break something.
     *
     * The channels are still theirs: what cannot be silenced is the in-app
     * record, not the SMS.
     */
    public function isOptional(): bool
    {
        return match ($this) {
            self::SessionCancelled,
            self::SessionRescheduled,
            self::CertificateRevoked,
            self::EnrollmentOverdue,
            // Nobody may mute the email that lets them back into their own
            // account, or the one that gives them an account at all.
            self::Invitation,
            self::PasswordReset     => false,
            default                 => true,
        };
    }

    /**
     * How long it stays on the bell.
     *
     * Null means indefinitely. A reminder for a session that has since
     * happened is clutter, and clutter is how an inbox stops being read.
     */
    public function livesForDays(): ?int
    {
        return match ($this) {
            self::SessionReminder   => 2,
            self::EnrollmentDueSoon => 30,
            self::Announcement      => 90,
            // As long as the link they carry, so a dead one does not sit
            // in a mailbox looking live.
            self::PasswordReset     => 1,
            self::Invitation        => 14,
            default                 => null,
        };
    }

    /**
     * What the thing it is about is called, for `subject_type`.
     */
    public function subjectType(): ?string
    {
        return match ($this) {
            self::EnrollmentAssigned,
            self::EnrollmentDueSoon,
            self::EnrollmentOverdue,
            self::CourseCompleted    => 'course',

            self::AssessmentGraded   => 'assessment',

            self::CertificateIssued,
            self::CertificateRevoked => 'certificate',

            self::SessionScheduled,
            self::SessionReminder,
            self::SessionRescheduled,
            self::SessionCancelled   => 'training_session',

            self::Welcome,
            self::Invitation,
            self::PasswordReset,
            self::Announcement       => null,
        };
    }

    /**
     * Whether this may be sent to an account that cannot yet be signed into.
     *
     * Recipients are otherwise restricted to active accounts, which is
     * right for everything that pesters somebody about work: a suspended
     * account should not be chased about an overdue course.
     *
     * The invitation is the one exception, and it is not a small one — it
     * is the *only* thing that can turn an invited account into an active
     * one, so excluding it made inviting anybody impossible.
     */
    public function reachesPendingAccounts(): bool
    {
        return $this === self::Invitation;
    }

    public function label(): string
    {
        return match ($this) {
            self::Welcome            => 'Welcome',
            self::Invitation         => 'Invitation',
            self::PasswordReset      => 'Password reset',
            self::EnrollmentAssigned => 'Assigned a course',
            self::EnrollmentDueSoon  => 'Course due soon',
            self::EnrollmentOverdue  => 'Course overdue',
            self::CourseCompleted    => 'Course completed',
            self::AssessmentGraded   => 'Assessment marked',
            self::CertificateIssued  => 'Certificate issued',
            self::CertificateRevoked => 'Certificate withdrawn',
            self::SessionScheduled   => 'Session scheduled',
            self::SessionReminder    => 'Session reminder',
            self::SessionRescheduled => 'Session moved',
            self::SessionCancelled   => 'Session cancelled',
            self::Announcement       => 'Announcement',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    /**
     * Maps the legacy's spellings onto the catalogue.
     *
     * `course_completion` and `course_completed` were both used for the same
     * event, and the ILT queue said `reminder` where the inbox said
     * `session_reminder`.
     */
    public static function fromLegacy(?string $value): ?self
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'course_completion'  => self::CourseCompleted,
            'reminder'           => self::SessionReminder,
            'enrollment'         => self::EnrollmentAssigned,
            'cancellation'       => self::SessionCancelled,
            'certificate'        => self::CertificateIssued,
            'assignment_due'     => self::EnrollmentDueSoon,
            'new_course'         => self::EnrollmentAssigned,
            'broadcast'          => self::Announcement,
            default              => self::tryFrom($value),
        };
    }
}
