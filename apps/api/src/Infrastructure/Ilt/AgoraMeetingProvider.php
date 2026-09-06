<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Ilt;

use Central\Api\Domain\Ilt\AttendanceRecord;
use Central\Api\Domain\Ilt\Meeting;
use Central\Api\Domain\Ilt\MeetingProvider;
use Central\Api\Domain\Ilt\MeetingProviderId;
use Central\Api\Domain\Ilt\MeetingRequest;

/**
 * The in-app provider. There is nothing to book on a remote platform: an Agora
 * "meeting" is just a channel the session owns, and the class runs inside the
 * LMS over the browser SDK. So `schedule()` mints a stable channel and a join
 * URL that points back into the SPA's own room, and the lifecycle calls that
 * exist to talk to Zoom/Meet/Teams have nothing to do here.
 *
 * Per-user RTC/RTM tokens are issued separately (see the session token endpoint)
 * when a learner actually opens the room — a token in a scheduled meeting record
 * would be stale by the time class starts.
 */
final class AgoraMeetingProvider implements MeetingProvider
{
    public function __construct(
        private readonly string $appId,
        private readonly string $appWebUrl,
    ) {
    }

    public function id(): MeetingProviderId
    {
        return MeetingProviderId::Agora;
    }

    public function schedule(MeetingRequest $request): Meeting
    {
        $sessionId = (string) ($request->externalRef ?? '');

        return $this->meeting($sessionId, $this->channelFor($sessionId), $request);
    }

    public function reschedule(string $externalId, MeetingRequest $request): Meeting
    {
        // Moving the class is only a new time — keep the same channel so a link
        // already emailed still works.
        $sessionId = (string) ($request->externalRef ?? '');

        return $this->meeting($sessionId, $externalId, $request);
    }

    public function cancel(string $externalId): void
    {
        // Nothing to cancel: an Agora channel exists only while people are in it
        // and closes when the last one leaves. Idempotent by nature.
    }

    public function find(string $externalId): ?Meeting
    {
        // No remote record to look up; the session row already holds it all.
        return null;
    }

    /**
     * @return list<AttendanceRecord>
     */
    public function fetchAttendance(string $externalId): array
    {
        // Recorded live as learners enter the room, never pulled back after.
        return [];
    }

    private function meeting(string $sessionId, string $channel, MeetingRequest $request): Meeting
    {
        $url = $this->roomUrl($sessionId);

        return new Meeting(
            provider: MeetingProviderId::Agora,
            externalId: $channel,
            joinUrl: $url,
            startsAt: $request->startsAt,
            durationMinutes: $request->durationMinutes,
            // The room decides host controls from the caller's role, so host and
            // learner open the same URL — no separate, leak-prone host link.
            hostUrl: $url,
            password: null,
            raw: ['channel' => $channel, 'appId' => $this->appId, 'mode' => 'agora'],
        );
    }

    /** A stable channel per session — ASCII, well under Agora's 64-byte limit. */
    private function channelFor(string $sessionId): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $sessionId) ?? '';

        return 'sess-' . ($clean !== '' ? $clean : substr(sha1($sessionId), 0, 24));
    }

    private function roomUrl(string $sessionId): string
    {
        return $this->appWebUrl . '/live-training/room/' . $sessionId;
    }
}
