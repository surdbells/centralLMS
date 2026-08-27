<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

use Central\Api\Domain\Enrollment\EnrollmentSource;

/**
 * Storage for training programmes, their sessions, enrolment and attendance.
 *
 * One interface: the four are only ever used together, and attendance in
 * particular has to be written in the same transaction as the enrolment figure
 * it feeds — the same discipline as course progress.
 *
 * Every method takes a tenant id and none omits it.
 */
interface TrainingRepository
{
    // --- programmes -------------------------------------------------------

    /**
     * @return array{items: list<LiveTraining>, total: int}
     */
    public function paginate(
        string $tenantId,
        int $page,
        int $perPage,
        ?string $search = null,
        ?TrainingStatus $status = null,
        bool $openToLearnersOnly = false,
    ): array;

    public function find(string $tenantId, string $id): ?LiveTraining;

    public function create(LiveTraining $training): LiveTraining;

    public function update(LiveTraining $training): LiveTraining;

    public function setStatus(string $tenantId, string $id, TrainingStatus $status): ?LiveTraining;

    public function softDelete(string $tenantId, string $id): bool;

    // --- sessions ---------------------------------------------------------

    /**
     * @return list<TrainingSession>
     */
    public function sessionsFor(string $tenantId, string $trainingId): array;

    public function findSession(string $tenantId, string $id): ?TrainingSession;

    public function createSession(TrainingSession $session): TrainingSession;

    public function updateSession(TrainingSession $session): TrainingSession;

    /**
     * Records what the meeting provider returned — or why it did not.
     *
     * Separate from `updateSession()` because these fields are never a
     * client's to set: they are the provider's answer.
     */
    public function saveMeeting(
        string $tenantId,
        string $sessionId,
        ?MeetingProviderId $platform,
        ?Meeting $meeting,
        ?string $error,
    ): ?TrainingSession;

    public function setSessionStatus(string $tenantId, string $id, SessionStatus $status): ?TrainingSession;

    /**
     * Issues a fresh QR token for a physical session.
     *
     * The window comes from the programme: valid from `qrValidBeforeStartMinutes`
     * before the start until `qrGracePeriodMinutes` after it.
     */
    public function issueQrToken(string $tenantId, string $sessionId, LiveTraining $training): ?TrainingSession;

    public function findSessionByQrToken(string $tenantId, string $token): ?TrainingSession;

    // --- enrolment --------------------------------------------------------

    /**
     * @param list<string> $userIds
     *
     * @return array{enrolled: list<string>, waitlisted: list<string>, alreadyEnrolled: list<string>, notFound: list<string>, rejected: list<string>}
     */
    public function enrol(
        string $tenantId,
        LiveTraining $training,
        array $userIds,
        EnrollmentSource $source,
        ?string $actorId,
    ): array;

    /**
     * @param list<string> $userIds
     *
     * @return list<string>
     */
    public function withdraw(string $tenantId, string $trainingId, array $userIds): array;

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function rosterFor(string $tenantId, string $trainingId, int $page, int $perPage): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findEnrolment(string $tenantId, string $trainingId, string $userId): ?array;

    /**
     * Every programme one person is on, with the sessions of each.
     *
     * {@see findEnrolment} answers for one programme at a time, which is no
     * use to somebody asking what they are booked on — they do not have the
     * list of programmes to ask about. The roster is the other direction and
     * needs the organiser permission, because it is other people's names.
     *
     * @return list<array<string, mixed>>
     */
    public function enrolmentsForUser(string $tenantId, string $userId): array;

    public function countEnrolled(string $tenantId, string $trainingId): int;

    /**
     * Everyone who should be told about this programme's sessions.
     *
     * Enrolled, not dropped, not waitlisted. Someone on the waiting list has
     * no place to be reminded about, and telling them to attend would be
     * worse than telling them nothing.
     *
     * @return list<string>
     */
    public function enrolledUserIds(string $tenantId, string $trainingId): array;

    // --- attendance -------------------------------------------------------

    /**
     * Marks one learner for one session and recomputes their attendance.
     *
     * Both in one transaction, so the programme figure never disagrees with
     * the sessions underneath it.
     *
     * @param array<string, mixed> $details
     *
     * @return array{attendance: array<string, mixed>, summary: AttendanceSummary}
     */
    public function mark(
        string $tenantId,
        TrainingSession $session,
        LiveTraining $training,
        string $userId,
        AttendanceStatus $status,
        AttendanceMode $mode,
        array $details = [],
    ): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function attendanceFor(string $tenantId, string $sessionId): array;

    /**
     * Recomputes one learner's attendance across the whole programme.
     */
    public function recalculate(string $tenantId, LiveTraining $training, string $userId): ?AttendanceSummary;
}
