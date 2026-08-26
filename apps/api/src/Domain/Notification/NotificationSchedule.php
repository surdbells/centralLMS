<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * A notification planned for later, expressed relative to something else.
 *
 * The design point worth reading: a schedule stores `offsetMinutes`, never a
 * due time. `live_training_reminders.scheduled_at` held a copy of the
 * session's start minus the offset and nothing recomputed it, so moving a
 * session left its reminders pointing at the old hour — the classic
 * stored-derived-value failure this port keeps running into, in the one place
 * where the consequence is a learner told "starts in 1 hour" about something
 * that finished yesterday.
 *
 * Here `dueAt()` takes the subject's *current* moment and subtracts. Moving
 * the session moves the reminder, with nothing to remember to update.
 */
final readonly class NotificationSchedule
{
    /**
     * @param list<NotificationChannel> $channels
     */
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public NotificationType $type,
        public string $subjectType,
        public string $subjectId,
        public int $offsetMinutes,
        public array $channels = [NotificationChannel::InApp, NotificationChannel::Email],
        public ScheduleStatus $status = ScheduleStatus::Pending,
        public ?\DateTimeImmutable $expandedAt = null,
        public ?int $recipients = null,
        public ?string $error = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * When this should go out, given when the thing it is about happens.
     */
    public function dueAt(\DateTimeImmutable $subjectStartsAt): \DateTimeImmutable
    {
        return $subjectStartsAt->modify(sprintf('-%d minutes', $this->offsetMinutes));
    }

    public function isDue(\DateTimeImmutable $subjectStartsAt, \DateTimeImmutable $now): bool
    {
        return $this->status->isOpen() && $this->dueAt($subjectStartsAt) <= $now;
    }

    /**
     * Whether its moment has gone by without it firing.
     *
     * A reminder is not worth sending after the thing has started; a learner
     * told at 09:15 that a 09:00 session "starts in 1 hour" is worse off than
     * one told nothing. The grace period covers a worker that has been down
     * for a few minutes, which should still send.
     */
    public function hasLapsed(
        \DateTimeImmutable $subjectStartsAt,
        \DateTimeImmutable $now,
        int $graceMinutes = 15,
    ): bool {
        return $this->dueAt($subjectStartsAt)->modify(sprintf('+%d minutes', $graceMinutes)) < $now
            || $subjectStartsAt <= $now;
    }

    /**
     * The offsets a training's configuration asks for, as schedules.
     *
     * Duplicated offsets collapse: `{60, 60}` is one reminder, and the unique
     * index would reject the second anyway.
     *
     * @param  list<int>                 $offsetsMinutes
     * @param  list<NotificationChannel> $channels
     * @return list<self>
     */
    public static function forSubject(
        string $tenantId,
        NotificationType $type,
        string $subjectType,
        string $subjectId,
        array $offsetsMinutes,
        array $channels,
    ): array {
        $seen      = [];
        $schedules = [];

        foreach ($offsetsMinutes as $offset) {
            $offset = (int) $offset;

            if ($offset < 0 || $offset > 43200 || isset($seen[$offset])) {
                continue;
            }

            $seen[$offset] = true;

            $schedules[] = new self(
                id: null,
                tenantId: $tenantId,
                type: $type,
                subjectType: $subjectType,
                subjectId: $subjectId,
                offsetMinutes: $offset,
                channels: $channels === [] ? [NotificationChannel::InApp] : $channels,
            );
        }

        // Longest lead time first, so a list of a session's reminders reads
        // in the order they will arrive.
        usort($schedules, static fn (self $a, self $b): int => $b->offsetMinutes <=> $a->offsetMinutes);

        return $schedules;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'type'          => $this->type->value,
            'subjectType'   => $this->subjectType,
            'subjectId'     => $this->subjectId,
            'offsetMinutes' => $this->offsetMinutes,
            'channels'      => array_map(
                static fn (NotificationChannel $c): string => $c->value,
                $this->channels,
            ),
            'status'        => $this->status->value,
            'expandedAt'    => $this->expandedAt?->format(\DateTimeInterface::ATOM),
            'recipients'    => $this->recipients,
            'error'         => $this->error,
        ];
    }
}
