<?php

declare(strict_types=1);

namespace Central\Api\Domain\Analytics;

/**
 * The handful of cross-cutting analytics the admin Analytics page needs that
 * the report engine did not already express: the compliance three-way, the
 * adoption/activation rates, and tenant-wide course satisfaction.
 *
 * Everything else the page shows comes from the existing admin dashboard and
 * the engagement endpoint; this fills only the named gaps.
 */
interface AnalyticsRepository
{
    /**
     * Mandatory training as Completed / In Progress / Yet to Attempt.
     *
     * "Yet to attempt" is `not_started`: an enrolment nobody has opened. Dropped
     * enrolments are not training anybody still owes, so they are excluded.
     *
     * @return array{completed: int, inProgress: int, yetToAttempt: int, total: int}
     */
    public function complianceStatus(string $tenantId): array;

    /**
     * How much of the organisation is on the platform and using it.
     *
     * `activated` is anybody who has ever signed in; `adoptionRate` is that over
     * the whole headcount. `firstTimeLoginRate` is, of the people added in the
     * window, how many have since signed in at least once.
     *
     * @return array{
     *     people: int, activated: int, neverSignedIn: int,
     *     newUsers: int, newActivated: int,
     *     adoptionRate: float, firstTimeLoginRate: float
     * }
     */
    public function adoption(string $tenantId, \DateTimeImmutable $since): array;

    /**
     * Tenant-wide course satisfaction from the published 5-star reviews: the
     * average, the count, the star distribution, and the best- and worst-rated
     * courses (minimum a handful of reviews, so one grudge cannot top the list).
     *
     * @return array{
     *     average: float, count: int, distribution: array<int, int>,
     *     top: list<array<string, mixed>>, low: list<array<string, mixed>>
     * }
     */
    public function satisfaction(string $tenantId): array;

    /**
     * The custom profile fields an organisation can slice analytics by — its
     * text and dropdown fields (Division, Subsidiary, Entity …). The core org
     * columns are branch/department/team; these are the tenant's own.
     *
     * @return list<array{key: string, label: string}>
     */
    public function sliceableFields(string $tenantId): array;

    /**
     * Enrolment and completion grouped by the values of one custom field, so an
     * admin can read completion by Division / Subsidiary / Entity. Unset values
     * fall under "—".
     *
     * @return list<array{value: string, enrolled: int, completed: int, rate: float}>
     */
    public function completionByCustomField(string $tenantId, string $fieldKey): array;

    /**
     * Assessment outcomes tenant-wide: attempts, distinct learners, average
     * score and the pass rate over graded attempts.
     *
     * @return array{attempts: int, learners: int, averageScore: float, passRate: float}
     */
    public function assessment(string $tenantId): array;

    /**
     * Competency readiness: of the requirement rows on reviewed/finalised
     * assessments, how many are met (gap of zero) — the "% meeting role
     * competency" figure.
     *
     * @return array{items: int, met: int, rate: float}
     */
    public function competencyReadiness(string $tenantId): array;

    /** Average number of courses completed per active employee. */
    public function avgCoursesPerEmployee(string $tenantId): float;

    /**
     * Competency readiness per cycle (oldest first), so the gap-closure trend —
     * how readiness has moved from one cycle to the next — can be read off it.
     *
     * @return list<array{cycle: string, items: int, met: int, rate: float, avgGap: float}>
     */
    public function competencyTrend(string $tenantId): array;

    /**
     * Discussion-forum participation over the window: threads and replies,
     * distinct participants, reactions given, and how many questions reached
     * an accepted answer.
     *
     * @return array{posts: int, threads: int, replies: int, participants: int, reactions: int, answeredThreads: int, answerRate: float}
     */
    public function forumParticipation(string $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
