<?php

declare(strict_types=1);

namespace Central\Api\Application\Notification;

use Central\Api\Domain\Ilt\LiveTraining;
use Central\Api\Domain\Ilt\TrainingRepository;
use Central\Api\Domain\Ilt\TrainingSession;
use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationRepository;
use Central\Api\Domain\Notification\NotificationSchedule;
use Central\Api\Domain\Notification\NotificationType;

/**
 * Everything the ILT module needs to say, in one place.
 *
 * `live_trainings.reminder_offsets_minutes` and `reminder_channels` have been
 * stored, validated and returned by the API since the ILT slice landed, and
 * until now nothing read them. This is what reads them.
 *
 * Kept out of the actions because the same four events are raised from three
 * of them, and because an action that both edits a session and decides who to
 * email is doing two jobs badly.
 *
 * Nothing here throws. Failing to send a reminder must not fail the session
 * edit that prompted it — see Notifier.
 */
final readonly class SessionNotifier
{
    private const SUBJECT = 'training_session';

    public function __construct(
        private NotificationRepository $notifications,
        private TrainingRepository $trainings,
        private Notifier $notifier,
    ) {
    }

    /**
     * Makes the session's reminder schedules match its programme's settings.
     *
     * Safe to call on every save: `syncSchedules` inserts what is missing,
     * cancels offsets no longer configured, and leaves schedules that have
     * already fired alone. Nothing here stores a due time — moving the
     * session moves its reminders because the due time is computed from the
     * session's current start. See NotificationSchedule.
     *
     * @return list<NotificationSchedule>
     */
    public function syncReminders(LiveTraining $training, TrainingSession $session): array
    {
        try {
            return $this->notifications->syncSchedules(
                $session->tenantId,
                self::SUBJECT,
                $session->id,
                NotificationType::SessionReminder,
                NotificationSchedule::forSubject(
                    $session->tenantId,
                    NotificationType::SessionReminder,
                    self::SUBJECT,
                    $session->id,
                    $training->reminderOffsetsMinutes,
                    NotificationChannel::fromList($training->reminderChannels),
                ),
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Tells the enrolled that a session now exists.
     */
    public function announceScheduled(LiveTraining $training, TrainingSession $session): int
    {
        return $this->tell($training, $session, NotificationType::SessionScheduled);
    }

    /**
     * Tells the enrolled that a session has moved.
     *
     * The reminders move with it for free, because a schedule stores an
     * offset rather than a time — which is exactly what
     * `live_training_reminders.scheduled_at` got wrong. The re-sync is here
     * for the case where the programme's offsets were edited in the same
     * breath as the session.
     */
    public function announceMoved(LiveTraining $training, TrainingSession $session): int
    {
        $this->syncReminders($training, $session);

        return $this->tell($training, $session, NotificationType::SessionRescheduled);
    }

    /**
     * Tells the enrolled that a session is off, and stops its reminders.
     *
     * The order matters: cancelling the schedules first means that even if
     * the announcement fails, nobody is later reminded to attend something
     * that is not happening.
     */
    public function announceCancelled(
        LiveTraining $training,
        TrainingSession $session,
        ?string $reason = null,
    ): int {
        try {
            $this->notifications->cancelSchedules($session->tenantId, self::SUBJECT, $session->id);
        } catch (\Throwable) {
            // Recorded by the repository's own logging; the announcement is
            // the more important half and still goes.
        }

        return $this->tell($training, $session, NotificationType::SessionCancelled, $reason);
    }

    private function tell(
        LiveTraining $training,
        TrainingSession $session,
        NotificationType $type,
        ?string $reason = null,
    ): int {
        $audience = $this->trainings->enrolledUserIds($session->tenantId, $training->id);

        if ($audience === []) {
            return 0;
        }

        $data = [
            // The session's own title when it has one, the programme's
            // otherwise: "Session 2" alone tells a learner nothing.
            'subjectTitle' => trim($session->title) !== '' ? $session->title : $training->title,
            'startsAt'     => $this->localStart($session),
        ];

        if ($reason !== null && trim($reason) !== '') {
            $data['reason'] = trim($reason);
        }

        return $this->notifier->notify(
            $session->tenantId,
            $audience,
            $type,
            $data,
            $session->id,
            NotificationChannel::fromList($training->reminderChannels),
        );
    }

    /**
     * The start time in the session's own timezone.
     *
     * A session in Lagos announced in UTC tells everybody the wrong hour, and
     * this is the one string in the message that people act on.
     */
    private function localStart(TrainingSession $session): string
    {
        try {
            return $session->startsAt
                ->setTimezone(new \DateTimeZone($session->timezone))
                ->format('D j M Y, H:i T');
        } catch (\Exception) {
            return $session->startsAt->format('D j M Y, H:i T');
        }
    }
}
