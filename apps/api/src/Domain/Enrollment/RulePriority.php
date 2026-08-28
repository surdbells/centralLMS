<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

/**
 * How urgent a rule is meant to make its enrolments look to the people they
 * land on.
 *
 * It does not change what the rule does — nothing here alters who is enrolled
 * or when it is due. It changes what the learner sees, and which reminders go
 * out first when a batch of them is sent.
 */
enum RulePriority: string
{
    case Low      = 'low';
    case Normal   = 'normal';
    case High     = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low      => 'Low',
            self::Normal   => 'Normal',
            self::High     => 'High',
            self::Critical => 'Critical',
        };
    }

    /** Higher goes out first. */
    public function weight(): int
    {
        return match ($this) {
            self::Low      => 0,
            self::Normal   => 1,
            self::High     => 2,
            self::Critical => 3,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
