<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

/**
 * Storage for learning paths.
 *
 * Every method takes a tenant id. The one method that does not exist is the
 * interesting one: there is nothing here that writes a learner's progress,
 * because progress is not stored. See {@see completionsFor()}.
 */
interface LearningPathRepository
{
    // --- authoring --------------------------------------------------------

    /**
     * @return array{items: list<LearningPath>, total: int}
     */
    public function paginate(string $tenantId, PathFilters $filters, int $page, int $perPage): array;

    public function find(string $tenantId, string $id): ?LearningPath;

    public function findBySlug(string $tenantId, string $slug): ?LearningPath;

    public function slugExists(string $tenantId, string $slug, ?string $exceptId = null): bool;

    public function save(LearningPath $path): LearningPath;

    /**
     * Publishing stamps the date, so "when did this go live" has an answer.
     */
    public function setStatus(string $tenantId, string $id, string $status): ?LearningPath;

    public function softDelete(string $tenantId, string $id): bool;

    // --- contents ---------------------------------------------------------

    /**
     * @return list<PathSection>
     */
    public function sections(string $tenantId, string $pathId): array;

    public function saveSection(PathSection $section): PathSection;

    public function deleteSection(string $tenantId, string $id): bool;

    /**
     * The items of a path, in order, with their subjects' titles resolved.
     *
     * @return list<PathItem>
     */
    public function items(string $tenantId, string $pathId): array;

    public function findItem(string $tenantId, string $id): ?PathItem;

    public function saveItem(PathItem $item): PathItem;

    public function deleteItem(string $tenantId, string $id): bool;

    /**
     * Reorders items in one statement.
     *
     * @param list<string> $orderedIds
     */
    public function reorderItems(string $tenantId, string $pathId, array $orderedIds): void;

    // --- enrolment --------------------------------------------------------

    /**
     * @return array{items: list<PathEnrollment>, total: int}
     */
    public function enrolments(string $tenantId, string $pathId, int $page, int $perPage): array;

    public function findEnrolment(string $tenantId, string $id): ?PathEnrollment;

    public function enrolmentFor(string $tenantId, string $pathId, string $userId): ?PathEnrollment;

    /**
     * Puts people on a path, skipping the ones already there.
     *
     * Also enrols them on the courses the path contains, in the same
     * transaction — a path whose members cannot open its courses is a reading
     * list. Idempotent, so somebody already taking one keeps the enrolment
     * they had, and with it the progress that lets the path credit work
     * finished before they joined.
     *
     * `$ruleId` names the standing rule that caused this, when one
     * did. Without it, "how many people has this rule enrolled" can only be
     * guessed at from dates.
     *
     * @param  list<string> $userIds
     * @return array{enrolled: int, skipped: int}
     */
    public function enrol(
        string $tenantId,
        string $pathId,
        array $userIds,
        string $source,
        ?string $assignedBy,
        ?\DateTimeImmutable $dueDate,
        bool $isMandatory,
        ?string $ruleId = null,
    ): array;

    public function withdraw(string $tenantId, string $pathId, string $userId): bool;

    /**
     * Records that a path is finished, and by which certificate.
     *
     * Called when the derived progress says so, so the stored flag can never
     * disagree with the items — it is a record of an event, not a summary.
     */
    public function markComplete(string $tenantId, string $enrolmentId, ?string $certificateId): ?PathEnrollment;

    /**
     * The paths this person is on.
     *
     * @return list<PathEnrollment>
     */
    public function forLearner(string $tenantId, string $userId, ?string $status = null): array;

    // --- progress ---------------------------------------------------------

    /**
     * When each item of a path was finished by one learner.
     *
     * This is the whole reason there is no progress table. The answer comes
     * from the records that already exist:
     *
     *  - a course item is done when the learner's enrolment on that course is
     *    complete;
     *  - an assessment item is done when they have a graded attempt that
     *    passed;
     *  - a session item is done when the register says they attended;
     *  - a link or a piece of media is done when somebody marked it, which is
     *    the only evidence those two can produce.
     *
     * So a path credits work finished before the learner ever joined it —
     * something the legacy could not express, since its item progress began at
     * `locked` on enrolment.
     *
     * @return array<string, string> item id => when, ATOM
     */
    public function completionsFor(string $tenantId, string $pathId, string $userId): array;

    /**
     * Marks an item done by hand, or as viewed.
     *
     * Only for the kinds that produce no evidence of their own, and for an
     * administrator overriding. Marking a course done here would be a second
     * opinion about the course.
     */
    public function markItem(
        string $tenantId,
        string $enrolmentId,
        string $itemId,
        string $source,
        ?string $completedBy,
        ?string $note,
    ): bool;

    public function unmarkItem(string $tenantId, string $enrolmentId, string $itemId): bool;

    /**
     * Headline counts for the paths screen.
     *
     * @return array<string, int>
     */
    public function statistics(string $tenantId): array;
}
