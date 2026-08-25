<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Ilt;

use Central\Api\Domain\Ilt\AttendanceRecord;
use Central\Api\Domain\Ilt\Meeting;
use Central\Api\Domain\Ilt\MeetingProviderId;
use Central\Api\Domain\Ilt\MeetingRequest;
use Central\Api\Domain\Ilt\TokenGrant;
use Ramsey\Uuid\Uuid;

/**
 * Google Meet, created as conference data on a Google Calendar event.
 *
 * Meet has no standalone "create a meeting" API — a Meet link exists because a
 * Calendar event asked for one. So this adapter schedules an event on the
 * connected user's calendar and reads the Meet link out of `conferenceData`.
 *
 * Scopes: `https://www.googleapis.com/auth/calendar.events`, plus
 * `https://www.googleapis.com/auth/admin.reports.audit.readonly` for
 * attendance. The reports scope only works when the connecting user is a
 * Google Workspace administrator; when it is absent, attendance returns empty
 * and the session falls back to QR check-in.
 *
 * `access_type=offline` with `prompt=consent` is required — without both,
 * Google issues no refresh token and the connection dies in an hour.
 *
 * @see https://developers.google.com/calendar/api/guides/create-events#video
 */
final class GoogleMeetProvider extends AbstractMeetingProvider
{
    private const AUTHORIZE_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL      = 'https://oauth2.googleapis.com/token';
    private const CALENDAR_API   = 'https://www.googleapis.com/calendar/v3';
    private const REPORTS_API    = 'https://admin.googleapis.com/admin/reports/v1';

    private const SCOPES = 'https://www.googleapis.com/auth/calendar.events '
        . 'https://www.googleapis.com/auth/admin.reports.audit.readonly';

    public function id(): MeetingProviderId
    {
        return MeetingProviderId::GoogleMeet;
    }

    // --- OAuth ------------------------------------------------------------

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'state'         => $state,
            // Both of these are mandatory to receive a refresh token.
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'include_granted_scopes' => 'true',
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): TokenGrant
    {
        return $this->grant(self::TOKEN_URL, [
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);
    }

    public function refresh(string $refreshToken): TokenGrant
    {
        // Google returns no refresh_token on refresh; TokenGrant carries null
        // and VideoIntegration::withTokens keeps the existing one.
        return $this->grant(self::TOKEN_URL, [
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);
    }

    // --- meetings ---------------------------------------------------------

    public function schedule(MeetingRequest $request): Meeting
    {
        $response = $this->json(
            'POST',
            $this->eventsUrl() . '?' . http_build_query([
                'conferenceDataVersion' => 1,
                // The LMS sends its own invitations; letting Google mail
                // everyone as well produces duplicate, differently-worded
                // notifications.
                'sendUpdates'           => 'none',
            ]),
            $this->buildPayload($request, withConference: true),
        );

        return $this->fromResponse($response, $request);
    }

    public function reschedule(string $externalId, MeetingRequest $request): Meeting
    {
        // No conference createRequest on update: sending one again replaces the
        // Meet link and invalidates every invitation already delivered.
        $response = $this->json(
            'PATCH',
            $this->eventsUrl($externalId) . '?' . http_build_query([
                'conferenceDataVersion' => 1,
                'sendUpdates'           => 'none',
            ]),
            $this->buildPayload($request, withConference: false),
        );

        return $this->fromResponse($response, $request);
    }

    public function cancel(string $externalId): void
    {
        // 410 Gone is what Calendar returns for an already-deleted event.
        $this->deleteIgnoringMissing($this->eventsUrl($externalId), absentStatuses: [404, 410]);
    }

    public function find(string $externalId): ?Meeting
    {
        $response = $this->getOrNull($this->eventsUrl($externalId), absentStatuses: [404, 410]);

        return $response === null ? null : $this->fromResponse($response);
    }

    /**
     * Attendance comes from the Workspace audit log, not from Calendar, keyed
     * by the meeting code embedded in the Meet URL.
     */
    public function fetchAttendance(string $externalId): array
    {
        $event = $this->getOrNull($this->eventsUrl($externalId), absentStatuses: [404, 410]);

        if ($event === null) {
            return [];
        }

        $meetingCode = $this->extractMeetingCode($event);

        if ($meetingCode === null) {
            return [];
        }

        // 403 = the connected user is not a Workspace admin, or the reports
        // scope was never granted. Both mean "no data", not "sync failed".
        $response = $this->getOrNull(
            sprintf('%s/activity/users/all/applications/meet?%s', self::REPORTS_API, http_build_query([
                'eventName' => 'call_ended',
                'filters'   => 'meeting_code==' . $meetingCode,
                'maxResults' => 500,
            ])),
            absentStatuses: [400, 403, 404],
        );

        if ($response === null) {
            return [];
        }

        $records = [];

        foreach ((array) ($response['items'] ?? []) as $item) {
            $endedAt = $this->parseTime($item['id']['time'] ?? null);

            foreach ((array) ($item['events'] ?? []) as $event2) {
                $parameters = $this->flattenParameters((array) ($event2['parameters'] ?? []));

                $durationSeconds = (int) ($parameters['duration_seconds'] ?? 0);
                $email           = $this->nullableString($parameters['identifier'] ?? null);
                $displayName     = $this->nullableString($parameters['display_name'] ?? null);

                if ($email === null && $displayName === null) {
                    continue;
                }

                // The audit log records when a participant *left* plus how long
                // they were present, so the join time is derived.
                $leftAt   = $endedAt;
                $joinedAt = $leftAt?->modify(sprintf('-%d seconds', $durationSeconds));

                if ($joinedAt === null) {
                    continue;
                }

                $records[] = new AttendanceRecord(
                    email: $email,
                    displayName: $displayName ?? $email ?? 'Unknown',
                    joinedAt: $joinedAt,
                    leftAt: $leftAt,
                    durationMinutes: (int) round($durationSeconds / 60),
                    externalUserId: $this->nullableString($parameters['identifier'] ?? null),
                );
            }
        }

        return AttendanceRecord::mergeByParticipant($records);
    }

    // --- internals --------------------------------------------------------

    /**
     * The audit log returns parameters as a list of {name, value|intValue|boolValue}.
     *
     * @param array<mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function flattenParameters(array $parameters): array
    {
        $flat = [];

        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $name = $this->nullableString($parameter['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $flat[$name] = $parameter['value']
                ?? $parameter['intValue']
                ?? $parameter['boolValue']
                ?? null;
        }

        return $flat;
    }

    /**
     * Meet codes look like `abc-defg-hij`; the audit log wants them without
     * the dashes.
     *
     * @param array<string, mixed> $event
     */
    private function extractMeetingCode(array $event): ?string
    {
        $uri = $this->nullableString($event['hangoutLink'] ?? null) ?? $this->videoEntryPoint($event);

        if ($uri === null) {
            return null;
        }

        if (preg_match('#meet\.google\.com/([a-z0-9-]+)#i', $uri, $matches) !== 1) {
            return null;
        }

        return str_replace('-', '', $matches[1]);
    }

    private function calendarId(): string
    {
        return (string) ($this->integration()->settings['calendar_id'] ?? 'primary');
    }

    private function eventsUrl(?string $eventId = null): string
    {
        $base = sprintf('%s/calendars/%s/events', self::CALENDAR_API, rawurlencode($this->calendarId()));

        return $eventId === null ? $base : $base . '/' . rawurlencode($eventId);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(MeetingRequest $request, bool $withConference): array
    {
        $payload = [
            'summary'     => mb_substr($request->topic, 0, 1024),
            'description' => $request->agenda,
            'start'       => [
                'dateTime' => $request->startsAt->format(\DateTimeInterface::RFC3339),
                'timeZone' => $request->timezone,
            ],
            'end' => [
                'dateTime' => $request->endsAt()->format(\DateTimeInterface::RFC3339),
                'timeZone' => $request->timezone,
            ],
            'attendees' => array_map(
                static fn (string $email): array => ['email' => $email],
                $request->attendeeEmails,
            ),
        ];

        if ($withConference) {
            $payload['conferenceData'] = [
                'createRequest' => [
                    // Google deduplicates on requestId, so a retried create
                    // returns the same conference rather than a second link.
                    'requestId'             => $request->externalRef ?? Uuid::uuid4()->toString(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ];
        }

        return array_filter($payload, static fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function fromResponse(array $response, ?MeetingRequest $request = null): Meeting
    {
        $startsAt = $this->parseTime($response['start']['dateTime'] ?? null)
            ?? ($request !== null ? $request->startsAt : $this->clock->now());

        $duration = $request !== null
            ? $request->durationMinutes
            : $this->durationFrom($response, $startsAt);

        return new Meeting(
            provider: $this->id(),
            externalId: (string) ($response['id'] ?? ''),
            joinUrl: $this->nullableString($response['hangoutLink'] ?? null)
                ?? $this->videoEntryPoint($response)
                ?? '',
            startsAt: $startsAt,
            durationMinutes: $duration,
            hostUrl: null,
            password: null,
            raw: $response,
        );
    }

    /**
     * `hangoutLink` is the convenient field but is not always populated on the
     * insert response, so fall back to the video entry point.
     *
     * @param array<string, mixed> $response
     */
    private function videoEntryPoint(array $response): ?string
    {
        foreach ((array) ($response['conferenceData']['entryPoints'] ?? []) as $entryPoint) {
            if (($entryPoint['entryPointType'] ?? null) === 'video') {
                return $this->nullableString($entryPoint['uri'] ?? null);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function durationFrom(array $response, \DateTimeImmutable $startsAt): int
    {
        $endsAt = $this->parseTime($response['end']['dateTime'] ?? null);

        if ($endsAt === null) {
            return 0;
        }

        return max(0, (int) round(($endsAt->getTimestamp() - $startsAt->getTimestamp()) / 60));
    }
}
