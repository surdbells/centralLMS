<?php

declare(strict_types=1);

namespace Central\Api\Domain\Gamification;

/**
 * What earned somebody points.
 *
 * Closed, because `user_points.point_type` was a `varchar(50)` and 122 rows
 * managed eight spellings for five things: `course` and `course_completion`,
 * `assessment` and `assessment_pass` and `quiz_passed`. A breakdown grouped on
 * that column showed each spelling as its own line.
 */
enum PointReason: string
{
    case CourseCompleted   = 'course_completed';
    case LessonCompleted   = 'lesson_completed';
    case AssessmentPassed  = 'assessment_passed';
    case CertificateEarned = 'certificate_earned';
    case SessionAttended   = 'session_attended';

    /** A run of consecutive days. Repeats, and has no subject. */
    case Streak = 'streak';

    /** Granted by a person. The only reason that needs an actor. */
    case Manual = 'manual';

    /**
     * What it is worth by default.
     *
     * A tenant may want its own numbers eventually; until something asks for
     * that, one table of values beats a settings screen nobody has requested.
     */
    public function defaultPoints(): int
    {
        return match ($this) {
            self::CourseCompleted   => 100,
            self::CertificateEarned => 50,
            self::AssessmentPassed  => 25,
            self::SessionAttended   => 20,
            self::LessonCompleted   => 10,
            self::Streak            => 5,
            self::Manual            => 0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CourseCompleted   => 'Course completed',
            self::LessonCompleted   => 'Lesson completed',
            self::AssessmentPassed  => 'Assessment passed',
            self::CertificateEarned => 'Certificate earned',
            self::SessionAttended   => 'Session attended',
            self::Streak            => 'Learning streak',
            self::Manual            => 'Awarded',
        };
    }

    /** What the points are about, for `subject_type`. */
    public function subjectType(): ?string
    {
        return match ($this) {
            self::CourseCompleted   => 'course',
            self::LessonCompleted   => 'lesson',
            self::AssessmentPassed  => 'assessment',
            self::CertificateEarned => 'certificate',
            self::SessionAttended   => 'training_session',
            self::Streak, self::Manual => null,
        };
    }

    /**
     * Maps the legacy's spellings onto the five things they meant.
     */
    public static function fromLegacy(?string $value): ?self
    {
        return match (strtolower(trim((string) $value))) {
            'course', 'course_completion', 'scorm_completion' => self::CourseCompleted,
            'lesson_completion'                               => self::LessonCompleted,
            'assessment', 'assessment_pass', 'quiz_passed'    => self::AssessmentPassed,
            'certificate', 'certificate_issued'               => self::CertificateEarned,
            default                                           => self::tryFrom(strtolower(trim((string) $value))),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
