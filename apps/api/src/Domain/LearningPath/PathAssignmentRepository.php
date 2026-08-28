<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

interface PathAssignmentRepository
{
    /**
     * The rules on one path, newest first.
     *
     * @return list<PathAssignment>
     */
    public function forPath(string $tenantId, string $pathId): array;

    public function find(string $tenantId, string $id): ?PathAssignment;

    public function save(PathAssignment $assignment): PathAssignment;

    /**
     * Pauses, resumes, finishes or cancels a rule.
     *
     * None of these touch the enrolments it already made: stopping a rule
     * stops it enrolling anybody new, and taking a course away from somebody
     * halfway through it is a different decision that nobody made here.
     */
    public function setStatus(string $tenantId, string $id, AssignmentStatus $status): ?PathAssignment;

    public function delete(string $tenantId, string $id): bool;

    /**
     * Enrols everybody the rule covers who is not on the path yet.
     *
     * Idempotent, and it has to be: the sweep runs it again every time, and a
     * rule that enrolled the same person twice a day would fill the path with
     * duplicates within a week. Returns how many were newly enrolled.
     */
    public function apply(string $tenantId, string $id): int;

    /**
     * Every active rule, for the sweep.
     *
     * A null tenant means every tenant — which is what the sweep wants, since
     * it runs from cron and has no session to take one from.
     *
     * @return list<PathAssignment>
     */
    public function running(?string $tenantId = null): array;

    /**
     * How many people the rule covers as it stands, without applying it.
     *
     * For the screen that says "this rule covers 412 people, 380 of them
     * already on the path" before anybody commits to it.
     *
     * @return array{covered: int, alreadyEnrolled: int, wouldEnrol: int}
     */
    public function preview(string $tenantId, string $id): array;

    /**
     * What has become of the people this rule enrolled.
     *
     * Counted from the enrolments, never stored: a rule's figures change every
     * time somebody finishes an item, and a stored count is wrong from the
     * first of those.
     *
     * @return array<string, mixed>
     */
    public function statistics(string $tenantId, string $id): array;

    /**
     * Who this rule enrolled who has not finished and is running out of time.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingLearners(string $tenantId, string $id): array;
}
