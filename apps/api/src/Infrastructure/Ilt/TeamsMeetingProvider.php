<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Ilt;

use Central\Api\Domain\Ilt\AttendanceRecord;
use Central\Api\Domain\Ilt\Meeting;
use Central\Api\Domain\Ilt\MeetingProviderId;
use Central\Api\Domain\Ilt\MeetingRequest;
use Central\Api\Domain\Ilt\TokenGrant;

/**
 * Microsoft Teams, via delegated Microsoft Graph permissions.
 *
 * Delegated rather than app-only on purpose: the connecting admin consents
 * once, meetings are created as them (`/me/onlineMeetings`), and no tenant-side
 * `New-CsApplicationAccessPolicy` PowerShell is required. App-only would need
 * that policy on every customer tenant, which is a support burden a SaaS
 * cannot carry.
 *
 * Scopes: `offline_access` (required for a refresh token),
 * `OnlineMeetings.ReadWrite`, and `OnlineMeetingArtifact.Read.All` for
 * attendance reports.
 *
 * @see https://learn.microsoft.com/graph/api/application-post-onlinemeetings
 */
final class TeamsMeetingProvider extends AbstractMeetingProvider
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
    private const LOGIN_BASE = 'https://login.microsoftonline.com';

    private const SCOPES = 'offline_access OnlineMeetings.ReadWrite OnlineMeetingArtifact.Read.All';

    public function id(): MeetingProviderId
    {
        return MeetingProviderId::Teams;
    }

    // --- OAuth ------------------------------------------------------------

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return sprintf('%s/%s/oauth2/v2.0/authorize', self::LOGIN_BASE, $this->tenantSegment()) . '?' . http_build_query([
            'client_id'     => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'response_mode' => 'query',
            'scope'         => self::SCOPES,
            'state'         => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): TokenGrant
    {
        return $this->grant($this->tokenUrl(), [
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'scope'         => self::SCOPES,
        ]);
    }

    public function refresh(string $refreshToken): TokenGrant
    {
        return $this->grant($this->tokenUrl(), [
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope'         => self::SCOPES,
        ]);
    }

    // --- meetings ---------------------------------------------------------

    public function schedule(MeetingRequest $request): Meeting
    {
        return $this->fromResponse(
            $this->json('POST', self::GRAPH_BASE . '/me/onlineMeetings', $this->buildPayload($request)),
            $request,
        );
    }

    public function reschedule(string $externalId, MeetingRequest $request): Meeting
    {
        return $this->fromResponse(
            $this->json('PATCH', $this->meetingUrl($externalId), $this->buildPayload($request)),
            $request,
        );
    }

    public function cancel(string $externalId): void
    {
        $this->deleteIgnoringMissing($this->meetingUrl($externalId));
    }

    public function find(string $externalId): ?Meeting
    {
        $response = $this->getOrNull($this->meetingUrl($externalId));

        return $response === null ? null : $this->fromResponse($response);
    }

    /**
     * Graph splits attendance across two calls: list the reports for a
     * meeting, then read the records of the most recent one.
     */
    public function fetchAttendance(string $externalId): array
    {
        // 403 here means the tenant consented before the artifact scope was
        // added — surfacing it as "no data" lets QR attendance carry the
        // session instead of failing the sync outright.
        $reports = $this->getOrNull(
            $this->meetingUrl($externalId) . '/attendanceReports',
            absentStatuses: [403, 404],
        );

        if ($reports === null) {
            return [];
        }

        $reportId = $this->latestReportId((array) ($reports['value'] ?? []));

        if ($reportId === null) {
            return [];
        }

        $records = $this->getOrNull(
            sprintf('%s/attendanceReports/%s/attendanceRecords', $this->meetingUrl($externalId), rawurlencode($reportId)),
            absentStatuses: [403, 404],
        );

        if ($records === null) {
            return [];
        }

        $attendance = [];

        foreach ((array) ($records['value'] ?? []) as $record) {
            $intervals = (array) ($record['attendanceIntervals'] ?? []);

            $joinedAt = $this->parseTime($intervals[0]['joinDateTime'] ?? null);
            $leftAt   = $this->parseTime($intervals[count($intervals) - 1]['leaveDateTime'] ?? null);

            if ($joinedAt === null) {
                continue;
            }

            $attendance[] = new AttendanceRecord(
                email: $this->nullableString($record['emailAddress'] ?? null),
                displayName: (string) ($record['identity']['displayName'] ?? 'Unknown'),
                joinedAt: $joinedAt,
                leftAt: $leftAt,
                durationMinutes: (int) round(((int) ($record['totalAttendanceInSeconds'] ?? 0)) / 60),
                externalUserId: $this->nullableString($record['identity']['id'] ?? null),
            );
        }

        return AttendanceRecord::mergeByParticipant($attendance);
    }

    // --- internals --------------------------------------------------------

    /**
     * `common` lets any Microsoft tenant consent. A tenant that pins its own
     * directory can store its tenant id in the integration settings.
     */
    private function tenantSegment(): string
    {
        $tenantId = $this->integration()->settings['ms_tenant_id']
            ?? $this->appConfig['tenant_id']
            ?? 'common';

        return rawurlencode((string) $tenantId);
    }

    private function tokenUrl(): string
    {
        return sprintf('%s/%s/oauth2/v2.0/token', self::LOGIN_BASE, $this->tenantSegment());
    }

    private function meetingUrl(string $meetingId): string
    {
        return sprintf('%s/me/onlineMeetings/%s', self::GRAPH_BASE, rawurlencode($meetingId));
    }

    /**
     * @param array<mixed> $reports
     */
    private function latestReportId(array $reports): ?string
    {
        $latest     = null;
        $latestTime = null;

        foreach ($reports as $report) {
            if (!is_array($report)) {
                continue;
            }

            $endTime = $this->parseTime($report['meetingEndDateTime'] ?? null);
            $id      = $this->nullableString($report['id'] ?? null);

            if ($id === null) {
                continue;
            }

            if ($latestTime === null || ($endTime !== null && $endTime > $latestTime)) {
                $latest     = $id;
                $latestTime = $endTime;
            }
        }

        return $latest;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(MeetingRequest $request): array
    {
        $payload = [
            'startDateTime'       => $request->startsAtIso(),
            'endDateTime'         => $request->endsAtIso(),
            'subject'             => mb_substr($request->topic, 0, 255),
            'allowedPresenters'   => 'organizer',
            'lobbyBypassSettings' => [
                // Colleagues skip the lobby; external learners wait to be let
                // in, which is the closest Teams gets to a waiting room.
                'scope'                 => $request->waitingRoom ? 'organization' : 'everyone',
                'isDialInBypassEnabled' => false,
            ],
        ];

        if ($request->recordAutomatically) {
            $payload['recordAutomatically'] = true;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function fromResponse(array $response, ?MeetingRequest $request = null): Meeting
    {
        $startsAt = $this->parseTime($response['startDateTime'] ?? null)
            ?? ($request !== null ? $request->startsAt : $this->clock->now());

        $duration = $request !== null
            ? $request->durationMinutes
            : $this->durationFrom($response, $startsAt);

        return new Meeting(
            provider: $this->id(),
            externalId: (string) ($response['id'] ?? ''),
            joinUrl: (string) ($response['joinWebUrl'] ?? $response['joinUrl'] ?? ''),
            startsAt: $startsAt,
            durationMinutes: $duration,
            // Teams has no separate host link: the organiser joins the same URL
            // and is recognised by their signed-in identity.
            hostUrl: null,
            password: null,
            raw: $response,
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function durationFrom(array $response, \DateTimeImmutable $startsAt): int
    {
        $endsAt = $this->parseTime($response['endDateTime'] ?? null);

        if ($endsAt === null) {
            return 0;
        }

        return max(0, (int) round(($endsAt->getTimestamp() - $startsAt->getTimestamp()) / 60));
    }
}
