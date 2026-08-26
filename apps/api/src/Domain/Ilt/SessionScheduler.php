<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

use Central\Api\Domain\Ilt\Exception\MeetingProviderError;

/**
 * Creates, moves and cancels the real meeting behind a session.
 *
 * **This class is the point of the module.** The provider layer — Zoom, Google
 * Meet and Teams, 896 lines of it — was fully written in the legacy codebase
 * and had zero call sites. `LiveTraining::createVirtualMeeting()` returned
 * `https://meet.example.com/` plus random hex instead, so every virtual
 * session in production has NULL meeting columns and a join URL that goes
 * nowhere. This is the wiring that was missing.
 *
 * Scheduling is deliberately allowed to fail without failing the session.
 * A provider outage should not stop an organiser creating next month's
 * timetable; the session is saved with `meetingError` set, shows as needing
 * attention, and can be retried. The alternative — refusing the whole
 * operation — is how one expired OAuth token blocks a day of planning.
 */
final class SessionScheduler
{
    public function __construct(private readonly MeetingProviderResolver $providers)
    {
    }

    /**
     * Books a meeting for a session that needs one.
     *
     * @param list<string> $attendeeEmails
     *
     * @return array{meeting: ?Meeting, platform: ?MeetingProviderId, error: ?string}
     */
    public function schedule(
        TrainingSession $session,
        ?MeetingProviderId $platform = null,
        ?string $hostEmail = null,
        array $attendeeEmails = [],
    ): array {
        if (!$session->delivery->needsMeeting()) {
            return ['meeting' => null, 'platform' => null, 'error' => null];
        }

        try {
            $provider = $platform === null
                ? $this->providers->defaultForTenant($session->tenantId)
                : $this->providers->forTenant($session->tenantId, $platform);

            $meeting = $provider->schedule($this->request($session, $hostEmail, $attendeeEmails));

            return ['meeting' => $meeting, 'platform' => $provider->id(), 'error' => null];
        } catch (\Throwable $e) {
            // Recorded rather than thrown: see the class docblock.
            return [
                'meeting'  => null,
                'platform' => $platform,
                'error'    => $this->explain($e),
            ];
        }
    }

    /**
     * Moves an existing meeting to the session's new time.
     *
     * @param list<string> $attendeeEmails
     *
     * @return array{meeting: ?Meeting, error: ?string}
     */
    public function reschedule(
        TrainingSession $session,
        ?string $hostEmail = null,
        array $attendeeEmails = [],
    ): array {
        if ($session->externalMeetingId === null || $session->platform === null) {
            return ['meeting' => null, 'error' => 'This session has no meeting to move.'];
        }

        try {
            $provider = $this->providers->forTenant($session->tenantId, $session->platform);
            $meeting = $provider->reschedule(
                $session->externalMeetingId,
                $this->request($session, $hostEmail, $attendeeEmails),
            );

            return ['meeting' => $meeting, 'error' => null];
        } catch (\Throwable $e) {
            return ['meeting' => null, 'error' => $this->explain($e)];
        }
    }

    /**
     * Cancels the meeting behind a session.
     *
     * Never throws. A cancelled session with a meeting still standing is
     * untidy; a cancellation that fails outright because the provider is down
     * leaves the session live, which is worse.
     */
    public function cancel(TrainingSession $session): ?string
    {
        if ($session->externalMeetingId === null || $session->platform === null) {
            return null;
        }

        try {
            $this->providers->forTenant($session->tenantId, $session->platform)
                ->cancel($session->externalMeetingId);

            return null;
        } catch (\Throwable $e) {
            return $this->explain($e);
        }
    }

    /**
     * Reads back who actually attended, from the provider.
     *
     * The join and leave times come from Zoom, Meet or Teams rather than from
     * the learner's browser — a self-reported duration on something that
     * decides a certification is a number the learner controls.
     *
     * @return array{records: list<AttendanceRecord>, error: ?string}
     */
    public function fetchAttendance(TrainingSession $session): array
    {
        if ($session->externalMeetingId === null || $session->platform === null) {
            return ['records' => [], 'error' => 'This session has no meeting to read.'];
        }

        if (!$session->platform->supportsAttendanceReport()) {
            return [
                'records' => [],
                'error'   => sprintf(
                    '%s does not report attendance, so it has to be marked by hand.',
                    $session->platform->label(),
                ),
            ];
        }

        try {
            $provider = $this->providers->forTenant($session->tenantId, $session->platform);

            return [
                // Merged first: providers report one row per join, so somebody
                // whose connection dropped three times appears three times.
                'records' => AttendanceRecord::mergeByParticipant(
                    $provider->fetchAttendance($session->externalMeetingId),
                ),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return ['records' => [], 'error' => $this->explain($e)];
        }
    }

    /**
     * @param list<string> $attendeeEmails
     */
    private function request(
        TrainingSession $session,
        ?string $hostEmail,
        array $attendeeEmails,
    ): MeetingRequest {
        return new MeetingRequest(
            topic: $session->title,
            startsAt: $session->startsAt,
            durationMinutes: $session->durationMinutes(),
            timezone: $session->timezone,
            agenda: $session->description,
            hostEmail: $hostEmail,
            attendeeEmails: $attendeeEmails,
            // A waiting room by default: an open meeting link for a corporate
            // training is one forward away from being public.
            waitingRoom: true,
            joinBeforeHost: $session->allowLateJoin,
            // Lets a provider recognise its own meeting on a retry rather than
            // creating a second one.
            externalRef: $session->id,
        );
    }

    /**
     * Turns a provider failure into something an organiser can act on.
     */
    private function explain(\Throwable $e): string
    {
        if ($e instanceof MeetingProviderError) {
            return $e->getMessage();
        }

        // Anything else is ours, not theirs. The detail goes to the log; the
        // organiser gets something true and useful instead of a stack trace.
        return 'The meeting could not be scheduled. Check the video integration and try again.';
    }
}
