<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

interface GradingAssignmentRepository
{
    /**
     * The assignments for a set of attempts, keyed by attempt id.
     *
     * Takes the whole set because the queue is read as a page: asking per
     * attempt is one query per row on a screen showing forty.
     *
     * @param list<string> $attemptIds
     *
     * @return array<string, GradingAssignment>
     */
    public function forAttempts(string $tenantId, array $attemptIds): array;

    public function find(string $tenantId, string $attemptId): ?GradingAssignment;

    /**
     * Hands an attempt to a marker.
     *
     * Replaces any existing assignment rather than adding one — an attempt is
     * one person's to mark at a time, and two rows would leave no way to say
     * which is current. Reassigning also clears a previous skip, because the
     * new marker has not set it aside.
     */
    public function assign(
        string $tenantId,
        string $attemptId,
        ?string $assignedTo,
        ?string $assignedBy,
        int $priority = 0,
        ?string $notes = null,
    ): GradingAssignment;

    /** Puts an attempt back in the general queue. */
    public function release(string $tenantId, string $attemptId): bool;

    /**
     * Sets one aside without giving it up.
     *
     * The row stays so the next coordinator can see it was skipped and why,
     * rather than handing it back to the person who could not do it.
     */
    public function skip(string $tenantId, string $attemptId, ?string $reason): ?GradingAssignment;

    public function setPriority(string $tenantId, string $attemptId, int $priority): ?GradingAssignment;

    /**
     * Assigns a batch in one go, evenly across the markers given.
     *
     * Round-robin rather than "everything to whoever has least": the latter
     * needs a count of open work per marker, which is another derived figure,
     * and gets it wrong the moment two coordinators assign at once.
     *
     * @param list<string> $attemptIds
     * @param list<string> $markerIds
     *
     * @return int how many were assigned
     */
    public function assignEvenly(
        string $tenantId,
        array $attemptIds,
        array $markerIds,
        ?string $assignedBy,
    ): int;
}
