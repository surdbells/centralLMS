<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

interface EnrollmentRuleRepository
{
    /**
     * The rules on one thing, newest first.
     *
     * @return list<EnrollmentRule>
     */
    public function forSubject(string $tenantId, RuleSubject $kind, string $subjectId): array;

    public function find(string $tenantId, string $id): ?EnrollmentRule;

    public function save(EnrollmentRule $rule): EnrollmentRule;

    /**
     * Pauses, resumes, finishes or cancels a rule.
     *
     * None of these touch the enrolments it already made: stopping a rule
     * stops it enrolling anybody new, and taking something away from somebody
     * halfway through it is a different decision that nobody made here.
     */
    public function setStatus(string $tenantId, string $id, RuleStatus $status): ?EnrollmentRule;

    public function delete(string $tenantId, string $id): bool;

    /**
     * Enrols everybody the rule covers who is not on its subject yet.
     *
     * Idempotent, and it has to be: the sweep runs it again every hour, and a
     * rule that enrolled the same person twice a day would fill the subject
     * with duplicates within a week. Returns how many were newly enrolled.
     */
    public function apply(string $tenantId, string $id): int;

    /**
     * Every active rule, for the sweep.
     *
     * A null tenant means every tenant — which is what the sweep wants, since
     * it runs from cron and has no session to take one from.
     *
     * @return list<EnrollmentRule>
     */
    public function running(?string $tenantId = null): array;

    /**
     * How many people the rule covers as it stands, without applying it.
     *
     * For the screen that says "this rule covers 412 people, 380 of them
     * already enrolled" before anybody commits to it.
     *
     * `subjectAccepting` is false when the thing the rule points at would take
     * nobody — a draft programme, say. The rule is still a reasonable one to
     * set up early; it simply enrols nobody until that changes, and saying
     * "would enrol 412" and then enrolling none is what costs an afternoon.
     *
     * @return array{covered: int, alreadyEnrolled: int, wouldEnrol: int, subjectAccepting: bool}
     */
    public function preview(string $tenantId, string $id): array;

    /**
     * What has become of the people this rule enrolled.
     *
     * @return array<string, mixed>
     */
    public function statistics(string $tenantId, string $id): array;

    /**
     * Who this rule enrolled who has not finished.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingLearners(string $tenantId, string $id): array;
}
