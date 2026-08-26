<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * A schedule together with the current start time of the thing it is about.
 *
 * The pairing is the whole point: the due time is derived from
 * `subjectStartsAt` every time it is asked for, so a session that has moved
 * since the schedule was created is already accounted for.
 */
final readonly class DueSchedule
{
    /**
     * @param list<string> $audience user ids to notify
     */
    public function __construct(
        public NotificationSchedule $schedule,
        public \DateTimeImmutable $subjectStartsAt,
        public string $subjectTitle,
        public array $audience,
    ) {
    }

    public function dueAt(): \DateTimeImmutable
    {
        return $this->schedule->dueAt($this->subjectStartsAt);
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        return $this->schedule->isDue($this->subjectStartsAt, $now);
    }

    public function hasLapsed(\DateTimeImmutable $now): bool
    {
        return $this->schedule->hasLapsed($this->subjectStartsAt, $now);
    }
}
