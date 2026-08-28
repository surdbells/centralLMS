<?php

declare(strict_types=1);

namespace Central\Api\Domain\Audience;

/**
 * A way of naming a group of people without listing them.
 *
 * The same vocabulary for anything that addresses a group: an announcement,
 * a standing assignment, an enrolment rule. Adding a way of describing people
 * should mean adding one case here, not one case in each feature.
 */
enum AudienceTarget: string
{
    case All        = 'all';
    case Role       = 'role';
    case Department = 'department';
    case Branch     = 'branch';
    case Team       = 'team';
    /** Everybody on a course. */
    case Course     = 'course';
    /** Everybody on a learning path. */
    case Path       = 'path';
    /** A list of people chosen by hand. */
    case Custom     = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::All        => 'Everybody',
            self::Role       => 'By role',
            self::Department => 'By department',
            self::Branch     => 'By branch',
            self::Team       => 'By team',
            self::Course     => 'People on a course',
            self::Path       => 'People on a learning path',
            self::Custom     => 'Chosen people',
        };
    }

    /** What the criteria must name, for the message when they are missing. */
    public function noun(): string
    {
        return match ($this) {
            self::All        => 'audience',
            self::Role       => 'role',
            self::Department => 'department',
            self::Branch     => 'branch',
            self::Team       => 'team',
            self::Course     => 'course',
            self::Path       => 'learning path',
            self::Custom     => 'people',
        };
    }

    /**
     * Whether this needs anything beyond the kind itself.
     *
     * "Everybody" does not. The rest resolve to nobody without it, and
     * something that reached nobody still looks as though it ran.
     */
    public function needsCriteria(): bool
    {
        return $this !== self::All;
    }

    /**
     * The criteria keys this kind is written with, most specific first.
     *
     * Two spellings because both are in the wild; accepting either here is
     * cheaper than making every caller remember which one their feature
     * happened to store.
     *
     * @return list<string>
     */
    public function criteriaKeys(): array
    {
        return match ($this) {
            self::All        => [],
            self::Role       => ['roleIds', 'roles'],
            self::Department => ['departmentIds', 'departments'],
            self::Branch     => ['branchIds', 'branches'],
            self::Team       => ['teamIds', 'teams'],
            self::Course     => ['courseIds', 'courseId', 'courses'],
            self::Path       => ['pathIds', 'pathId', 'paths'],
            self::Custom     => ['userIds', 'users'],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
