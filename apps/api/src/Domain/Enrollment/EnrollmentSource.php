<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

/**
 * How the learner came to be on the course.
 *
 * The legacy column was free text and had drifted across two conventions at
 * once — 'Bulk Assignment', 'Team Assignment', 'Branch Assignment' and
 * 'self_enrollment' all appear in the live data. `fromLegacy()` folds them in.
 */
enum EnrollmentSource: string
{
    /** The learner enrolled themselves from the catalogue. */
    case SelfEnrolled = 'self';

    /** An administrator picked this person specifically. */
    case Direct = 'direct';

    /** An administrator picked a list of people. */
    case Bulk = 'bulk';

    case Team = 'team';
    case Branch = 'branch';
    case Department = 'department';

    /**
     * A standing rule put them here, and will keep putting people here.
     *
     * Distinct from `Bulk`: bulk is one administrator's decision on one
     * afternoon, and a rule outlives the afternoon. Telling them apart is how
     * "why am I on this" has an answer a year later.
     */
    case Rule = 'rule';

    public static function fromLegacy(?string $value): self
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace([' ', '-'], '_', $value);

        return match ($value) {
            'self', 'self_enrollment', 'self_enrolment', 'self_assignment' => self::SelfEnrolled,
            'team', 'team_assignment'             => self::Team,
            'branch', 'branch_assignment'         => self::Branch,
            'department', 'department_assignment' => self::Department,
            'bulk', 'bulk_assignment'             => self::Bulk,
            // 'Direct Assignment' and anything unrecognised. Direct is the
            // honest default: someone put them there, and we cannot say how.
            default => self::Direct,
        };
    }

    /**
     * Whether the learner chose this themselves.
     *
     * Assigned work can carry a due date and count towards compliance; work
     * someone picked up voluntarily should not be reported the same way.
     */
    public function wasChosenByTheLearner(): bool
    {
        return $this === self::SelfEnrolled;
    }

    /**
     * The audience the assignment was aimed at, if it was a group.
     *
     * Returns the lookup kind, so the reason an enrolment exists can be shown
     * as "assigned to the Operations department" rather than a bare label.
     */
    public function groupKind(): ?string
    {
        return match ($this) {
            self::Team       => 'team',
            self::Branch     => 'branch',
            self::Department => 'department',
            default          => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SelfEnrolled => 'Self-enrolled',
            self::Direct       => 'Assigned directly',
            self::Bulk         => 'Bulk assignment',
            self::Team         => 'Team assignment',
            self::Branch       => 'Branch assignment',
            self::Department   => 'Department assignment',
            self::Rule         => 'Standing rule',
        };
    }
}
