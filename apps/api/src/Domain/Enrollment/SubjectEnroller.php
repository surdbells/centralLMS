<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

/**
 * The one thing that differs between subjects: where the enrolment lands.
 *
 * Everything else about a standing rule — who it covers, when it is due, what
 * pausing it means, how often the sweep runs — is the same for a learning path
 * and a live training programme, and belongs in one place. This is the seam
 * where they part.
 *
 * Every implementation must be idempotent. The sweep applies each rule again
 * every hour, and a rule that enrolled the same person twice a day would fill
 * the subject with duplicates inside a week.
 */
interface SubjectEnroller
{
    public function subject(): RuleSubject;

    /**
     * Whether the thing the rule points at still exists.
     *
     * The sweep skips a rule whose subject has been deleted rather than
     * failing: an archived path should not stop every other rule running.
     */
    public function exists(string $tenantId, string $subjectId): bool;

    /** For the screen's title, and for the reminder's subject line. */
    public function titleOf(string $tenantId, string $subjectId): ?string;

    /**
     * Whether the subject would take anybody right now.
     *
     * A draft programme takes nobody, and a rule pointed at one enrols nobody
     * until it is published — which is a perfectly sensible way to set one up,
     * and a baffling result to be given without being told why. The preview
     * says so rather than promising an enrolment the sweep will not make.
     */
    public function acceptsEnrolments(string $tenantId, string $subjectId): bool;

    /**
     * Enrols the people who are not on it yet. Returns how many were new.
     *
     * @param list<string> $userIds
     */
    public function enrol(
        string $tenantId,
        string $subjectId,
        array $userIds,
        string $ruleId,
        ?\DateTimeImmutable $dueDate,
        bool $isMandatory,
        ?string $actorId,
    ): int;

    /**
     * How many of these people are already on it.
     *
     * @param list<string> $userIds
     */
    public function countEnrolled(string $tenantId, string $subjectId, array $userIds): int;

    /**
     * What became of the people one rule enrolled.
     *
     * Counted at read time, never stored: the figures change every time
     * somebody finishes something, and a stored count is wrong from the first
     * of those and never right again.
     *
     * @return array<string, mixed>
     */
    public function statistics(string $tenantId, string $ruleId): array;

    /**
     * Who this rule enrolled who has not finished.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingLearners(string $tenantId, string $ruleId): array;
}
