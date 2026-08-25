<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * What every video conferencing integration must be able to do.
 *
 * Ported from the legacy `VideoProviderInterface`, minus the OAuth handshake
 * methods. Connecting an account and scheduling a meeting are separate
 * concerns with separate lifetimes, so the handshake lives in
 * {@see VideoOAuthFlow} and this interface stays about meetings.
 *
 * Keep it small. Anything only one vendor supports belongs behind a capability
 * check on {@see MeetingProviderId} — a wide interface forces every adapter to
 * grow stubs, and stubs are precisely how the legacy ILT integration ended up
 * complete but never called.
 */
interface MeetingProvider
{
    public function id(): MeetingProviderId;

    /**
     * @throws Exception\MeetingProviderError
     */
    public function schedule(MeetingRequest $request): Meeting;

    /**
     * @throws Exception\MeetingProviderError
     */
    public function reschedule(string $externalId, MeetingRequest $request): Meeting;

    /**
     * Must be idempotent: cancelling an already-cancelled or already-deleted
     * meeting is a success, not an error. Session cancellation is retried by a
     * queue worker.
     *
     * @throws Exception\MeetingProviderError
     */
    public function cancel(string $externalId): void;

    /**
     * @throws Exception\MeetingProviderError
     */
    public function find(string $externalId): ?Meeting;

    /**
     * Who actually attended, for automatic attendance marking.
     *
     * Returns an empty list when the platform has no report yet — reports
     * appear minutes to hours after a meeting ends, so an empty result means
     * "not ready", never "nobody came". Callers must not mark absence from it.
     *
     * @return list<AttendanceRecord>
     *
     * @throws Exception\MeetingProviderError
     */
    public function fetchAttendance(string $externalId): array;
}
