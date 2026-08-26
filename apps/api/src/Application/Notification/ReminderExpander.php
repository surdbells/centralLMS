<?php

declare(strict_types=1);

namespace Central\Api\Application\Notification;

use Central\Api\Domain\Notification\DueSchedule;
use Central\Api\Domain\Notification\NotificationRepository;
use Central\Api\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * Turns schedules whose moment has come into actual notifications.
 *
 * Two steps rather than one because they fail differently. Expanding decides
 * *who* gets told and writes the rows; dispatching decides *how* and talks to
 * providers. A mail outage should not lose the record that a reminder was due,
 * and a session with no attendees should not look like a provider failure.
 */
final readonly class ReminderExpander
{
    public function __construct(
        private NotificationRepository $repository,
        private Notifier $notifier,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{considered: int, expanded: int, lapsed: int, empty: int, failed: int, notified: int}
     */
    public function expandDue(int $limit = 50): array
    {
        $now = $this->clock->now();

        $tally = [
            'considered' => 0,
            'expanded'   => 0,
            'lapsed'     => 0,
            'empty'      => 0,
            'failed'     => 0,
            'notified'   => 0,
        ];

        foreach ($this->repository->dueSchedules($now, $limit) as $due) {
            $tally['considered']++;

            // Checked here and not only in SQL: the query narrows by the
            // subject's start time, but whether a schedule is due is a
            // property of the offset, and the offset lives in the domain.
            if (!$due->isDue($now)) {
                continue;
            }

            // The session has started, or the reminder's moment went by while
            // the worker was down for longer than the grace period. Sending
            // now would tell someone a finished session "starts in 1 hour".
            if ($due->hasLapsed($now)) {
                $this->repository->markScheduleSkipped(
                    (string) $due->schedule->id,
                    $this->lapseReason($due, $now),
                );

                $tally['lapsed']++;

                continue;
            }

            if ($due->audience === []) {
                // Recorded as expanded with nought recipients rather than
                // skipped: nothing went wrong, there was simply nobody
                // enrolled, and leaving it pending would retry it forever.
                $this->repository->markScheduleExpanded((string) $due->schedule->id, $now, 0);
                $tally['empty']++;

                continue;
            }

            try {
                $notified = $this->notifier->notify(
                    $due->schedule->tenantId,
                    $due->audience,
                    $due->schedule->type,
                    [
                        'subjectTitle'  => $due->subjectTitle,
                        'startsAt'      => $due->subjectStartsAt->format('D j M Y, H:i T'),
                        'offsetMinutes' => $due->schedule->offsetMinutes,
                    ],
                    $due->schedule->subjectId,
                    $due->schedule->channels,
                );

                $this->repository->markScheduleExpanded((string) $due->schedule->id, $now, $notified);

                $tally['expanded']++;
                $tally['notified'] += $notified;
            } catch (\Throwable $e) {
                $this->repository->markScheduleFailed((string) $due->schedule->id, $e->getMessage());

                $this->logger->error('Could not expand a reminder schedule', [
                    'schedule' => $due->schedule->id,
                    'subject'  => $due->schedule->subjectId,
                    'error'    => $e->getMessage(),
                ]);

                $tally['failed']++;
            }
        }

        return $tally;
    }

    private function lapseReason(DueSchedule $due, \DateTimeImmutable $now): string
    {
        return $due->subjectStartsAt <= $now
            ? 'The session had already started.'
            : sprintf(
                'The reminder was due at %s and the grace period had passed.',
                $due->dueAt()->format(\DateTimeInterface::ATOM),
            );
    }
}
