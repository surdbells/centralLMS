<?php

declare(strict_types=1);

namespace Central\Api\Domain\Proctoring;

use Central\Api\Domain\Assessment\ProctoringLevel;

/**
 * Storage for the proctoring extension.
 *
 * Deliberately one interface rather than four: settings, sessions, violations
 * and snapshots are only ever used together, and splitting them would invite
 * core code to depend on a piece of the module.
 *
 * Every method takes a tenant id and none omits it.
 */
interface ProctoringRepository
{
    // --- entitlement ------------------------------------------------------

    public function entitlementsFor(string $tenantId): ModuleEntitlements;

    /**
     * Turns a module on or off for a tenant. Platform administration.
     */
    public function setEntitlement(string $tenantId, string $module, bool $enabled): ModuleEntitlements;

    // --- settings ---------------------------------------------------------

    /**
     * @return array{level: ProctoringLevel, settings: ProctoringSettings}|null
     */
    public function settingsFor(string $tenantId, string $assessmentId): ?array;

    /**
     * Writes the settings and keeps `assessments.proctoring_level` in step.
     *
     * Both in one transaction: the level lives on the assessment so the core
     * can read it without touching this module, and on the settings row so the
     * module is self-describing. Two copies of one fact only stay equal if
     * nothing can write one without the other.
     */
    public function saveSettings(
        string $tenantId,
        string $assessmentId,
        ProctoringLevel $level,
        ProctoringSettings $settings,
    ): void;

    // --- presets ----------------------------------------------------------

    /**
     * Presets available to a tenant: its own, plus the platform's.
     *
     * @return list<array<string, mixed>>
     */
    public function presetsFor(string $tenantId): array;

    // --- sessions ---------------------------------------------------------

    public function findSession(string $tenantId, string $id): ?ProctoringSession;

    public function findSessionForAttempt(string $tenantId, string $attemptId): ?ProctoringSession;

    /**
     * Opens the session for an attempt, or returns the one already open.
     *
     * Idempotent: a learner reloading during the pre-flight checks must not
     * start a second session, which would split the evidence in two.
     */
    public function openSession(string $tenantId, string $attemptId, string $userId): ProctoringSession;

    /**
     * @param array<string, mixed> $changes
     */
    public function updateSession(string $tenantId, string $sessionId, array $changes): ProctoringSession;

    // --- violations -------------------------------------------------------

    /**
     * Records an event and returns it with the session's recomputed standing.
     *
     * @param array<string, mixed> $event
     *
     * @return array{violation: array<string, mixed>, trust: TrustScore, action: string}
     */
    public function recordViolation(
        string $tenantId,
        ProctoringSession $session,
        ViolationType $type,
        ViolationSeverity $severity,
        ?string $description,
        ?string $questionId,
        array $event,
    ): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function violationsFor(string $tenantId, string $sessionId): array;

    /**
     * The trust score for a session, computed from its violations.
     */
    public function trustFor(string $tenantId, string $sessionId): TrustScore;

    /**
     * A reviewer's verdict on one event.
     */
    public function reviewViolation(
        string $tenantId,
        string $violationId,
        string $status,
        ?string $notes,
        string $reviewerId,
    ): bool;

    /**
     * A reviewer's verdict on a whole sitting: cleared or flagged, with notes.
     * Returns the updated session, or null when there is none to review.
     */
    public function reviewSession(
        string $tenantId,
        string $sessionId,
        string $status,
        ?string $notes,
        string $reviewerId,
    ): ?ProctoringSession;

    /**
     * A per-sitting breakdown of violations and snapshots plus the trust score.
     *
     * @return array<string, mixed>
     */
    public function sessionAnalytics(string $tenantId, string $sessionId): array;

    // --- snapshots --------------------------------------------------------

    /**
     * @param array<string, mixed> $snapshot
     */
    public function recordSnapshot(string $tenantId, ProctoringSession $session, array $snapshot): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function snapshotsFor(string $tenantId, string $sessionId, bool $flaggedOnly = false): array;

    /**
     * Flags or unflags one snapshot for review. Unflagging clears its reason.
     */
    public function flagSnapshot(string $tenantId, string $snapshotId, bool $flagged, ?string $reason): bool;

    /**
     * The session a snapshot belongs to, or null if it is not this tenant's.
     */
    public function findSnapshotSession(string $tenantId, string $snapshotId): ?string;

    /**
     * Sittings that a person should look at, worst first.
     *
     * A null assessment means every one of them. A reviewer sitting down
     * to work asks "what needs looking at?", and having to name an
     * assessment first meant opening each in turn to find out.
     *
     * `$pendingOnly` drops the ones already signed off — otherwise the
     * queue only ever grows and stops meaning anything.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function reviewQueue(
        string $tenantId,
        ?string $assessmentId,
        int $page,
        int $perPage,
        bool $pendingOnly = false,
    ): array;
}
