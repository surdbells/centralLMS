<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Ilt;

use Central\Api\Domain\Ilt\AttendanceRecord;
use Central\Api\Domain\Ilt\Meeting;
use Central\Api\Domain\Ilt\MeetingProviderId;
use Central\Api\Domain\Ilt\MeetingRequest;
use Central\Api\Domain\Ilt\TokenGrant;

/**
 * Zoom, via a general (per-tenant) OAuth app.
 *
 * Each tenant connects their own Zoom account through the consent screen, so
 * meetings are created under their licence and appear in their admin console.
 *
 * Scopes required: `meeting:write`, `meeting:read`, `user:read`, and
 * `report:read:admin` for attendance (which additionally needs a paid plan —
 * on a free account the report endpoint returns 400 and attendance falls back
 * to QR check-in).
 *
 * Note: Zoom rotates the refresh token on every refresh and invalidates the
 * previous one. AbstractMeetingProvider persists the new pair immediately for
 * exactly this reason.
 *
 * @see https://developers.zoom.us/docs/integrations/oauth/
 */
final class ZoomMeetingProvider extends AbstractMeetingProvider
{
    private const AUTHORIZE_URL = 'https://zoom.us/oauth/authorize';
    private const TOKEN_URL     = 'https://zoom.us/oauth/token';
    private const API_BASE      = 'https://api.zoom.us/v2';

    /** Zoom's type 2 = a meeting scheduled for a fixed time. */
    private const TYPE_SCHEDULED = 2;

    public function id(): MeetingProviderId
    {
        return MeetingProviderId::Zoom;
    }

    // --- OAuth ------------------------------------------------------------

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): TokenGrant
    {
        return $this->grant(self::TOKEN_URL, [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirectUri,
        ], $this->basicAuthHeader());
    }

    public function refresh(string $refreshToken): TokenGrant
    {
        return $this->grant(self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], $this->basicAuthHeader());
    }

    // --- meetings ---------------------------------------------------------

    public function schedule(MeetingRequest $request): Meeting
    {
        $host = $request->hostEmail !== null && $request->hostEmail !== '' ? $request->hostEmail : 'me';

        $response = $this->json(
            'POST',
            sprintf('%s/users/%s/meetings', self::API_BASE, rawurlencode($host)),
            $this->buildPayload($request),
        );

        return $this->toMeeting($response, $request);
    }

    public function reschedule(string $externalId, MeetingRequest $request): Meeting
    {
        // PATCH returns 204 with no body, so the meeting is read back rather
        // than assumed.
        $this->json(
            'PATCH',
            sprintf('%s/meetings/%s', self::API_BASE, rawurlencode($externalId)),
            $this->buildPayload($request),
        );

        return $this->find($externalId) ?? $this->toMeeting(['id' => $externalId], $request);
    }

    public function cancel(string $externalId): void
    {
        $this->deleteIgnoringMissing(sprintf('%s/meetings/%s', self::API_BASE, rawurlencode($externalId)));
    }

    public function find(string $externalId): ?Meeting
    {
        $response = $this->getOrNull(sprintf('%s/meetings/%s', self::API_BASE, rawurlencode($externalId)));

        return $response === null ? null : $this->fromResponse($response);
    }

    public function fetchAttendance(string $externalId): array
    {
        // The report endpoint only has data once the meeting has ended, and
        // 400 is what Zoom returns both for "no report yet" and "your plan
        // does not include reports". Neither is an error worth propagating —
        // an empty list means "nothing to mark yet".
        $response = $this->getOrNull(
            sprintf(
                '%s/report/meetings/%s/participants?%s',
                self::API_BASE,
                rawurlencode($externalId),
                http_build_query(['page_size' => 300]),
            ),
            absentStatuses: [400, 404],
        );

        if ($response === null) {
            return [];
        }

        $records = [];

        foreach ((array) ($response['participants'] ?? []) as $participant) {
            $joinedAt = $this->parseTime($participant['join_time'] ?? null);

            if ($joinedAt === null) {
                continue;
            }

            $records[] = new AttendanceRecord(
                email: $this->nullableString($participant['user_email'] ?? null),
                displayName: (string) ($participant['name'] ?? 'Unknown'),
                joinedAt: $joinedAt,
                leftAt: $this->parseTime($participant['leave_time'] ?? null),
                // Zoom reports seconds here, unlike the meeting object's minutes.
                durationMinutes: (int) round(((int) ($participant['duration'] ?? 0)) / 60),
                externalUserId: $this->nullableString($participant['id'] ?? null),
            );
        }

        // A learner who drops and rejoins appears once per join.
        return AttendanceRecord::mergeByParticipant($records);
    }

    // --- internals --------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function basicAuthHeader(): array
    {
        return ['Authorization' => 'Basic ' . base64_encode($this->clientId() . ':' . $this->clientSecret())];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(MeetingRequest $request): array
    {
        return array_filter([
            'topic'      => mb_substr($request->topic, 0, 200),
            'type'       => self::TYPE_SCHEDULED,
            'start_time' => $request->startsAtIso(),
            'duration'   => $request->durationMinutes,
            'timezone'   => $request->timezone,
            'agenda'     => $request->agenda !== null ? mb_substr($request->agenda, 0, 2000) : null,
            'settings'   => [
                'host_video'        => true,
                'participant_video' => false,
                'join_before_host'  => $request->joinBeforeHost,
                'waiting_room'      => $request->waitingRoom,
                'mute_upon_entry'   => true,
                'auto_recording'    => $request->recordAutomatically ? 'cloud' : 'none',
                // 0 = auto-approve. Enrolment is the LMS's job, not Zoom's.
                'approval_type'     => 0,
            ],
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function toMeeting(array $response, MeetingRequest $request): Meeting
    {
        return new Meeting(
            provider: $this->id(),
            externalId: (string) ($response['id'] ?? ''),
            joinUrl: (string) ($response['join_url'] ?? ''),
            startsAt: $request->startsAt,
            durationMinutes: $request->durationMinutes,
            hostUrl: $this->nullableString($response['start_url'] ?? null),
            password: $this->nullableString($response['password'] ?? null),
            raw: $response,
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function fromResponse(array $response): Meeting
    {
        return new Meeting(
            provider: $this->id(),
            externalId: (string) ($response['id'] ?? ''),
            joinUrl: (string) ($response['join_url'] ?? ''),
            startsAt: $this->parseTime($response['start_time'] ?? null) ?? $this->clock->now(),
            durationMinutes: (int) ($response['duration'] ?? 0),
            hostUrl: $this->nullableString($response['start_url'] ?? null),
            password: $this->nullableString($response['password'] ?? null),
            raw: $response,
        );
    }
}
