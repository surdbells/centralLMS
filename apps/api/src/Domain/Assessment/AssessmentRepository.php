<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * Storage for assessments and the questions attached to them.
 *
 * Every method takes a tenant id and none omits it.
 */
interface AssessmentRepository
{
    /**
     * @return array{items: list<Assessment>, total: int}
     */
    public function paginate(
        string $tenantId,
        int $page,
        int $perPage,
        ?string $search = null,
        ?AssessmentStatus $status = null,
        ?string $courseId = null,
    ): array;

    public function find(string $tenantId, string $id): ?Assessment;

    public function create(Assessment $assessment): Assessment;

    public function update(Assessment $assessment): Assessment;

    /**
     * Separate from `update()` because publishing is its own act with its own
     * permission, exactly as it is for courses.
     */
    public function setStatus(string $tenantId, string $id, AssessmentStatus $status): ?Assessment;

    public function softDelete(string $tenantId, string $id): bool;

    /**
     * The questions on an assessment, in order, each with its options.
     *
     * `pointsOverride` is applied by the caller through
     * `Question::pointsIn()`; it is returned alongside rather than folded in,
     * so an author can still see the bank's own value.
     *
     * @return list<array{question: Question, pointsOverride: ?float, sortOrder: int, isRequired: bool, sectionId: ?string}>
     */
    public function questionsFor(string $tenantId, string $assessmentId): array;

    /**
     * Attaches a question from a bank.
     *
     * @throws \RuntimeException when the question is already on it
     */
    public function attachQuestion(
        string $tenantId,
        string $assessmentId,
        string $questionId,
        ?float $pointsOverride,
        ?int $sortOrder,
        bool $isRequired,
    ): bool;

    public function detachQuestion(string $tenantId, string $assessmentId, string $questionId): bool;

    /**
     * @param list<string> $orderedQuestionIds
     */
    public function reorderQuestions(string $tenantId, string $assessmentId, array $orderedQuestionIds): int;

    /**
     * Recomputes `requires_manual_grading` from the questions attached.
     *
     * Called whenever the question set changes. Stored rather than derived on
     * read because the grading queue filters on it, and because it is a
     * property of the assessment's shape rather than an aggregate that drifts.
     */
    public function refreshManualGradingFlag(string $tenantId, string $assessmentId): void;

    /**
     * Which assessments ask a given question.
     *
     * Needed when a question is edited: changing its type can change
     * whether the assessments using it need a human marker.
     *
     * @return list<string>
     */
    public function assessmentIdsUsingQuestion(string $tenantId, string $questionId): array;

    // --- sections ---------------------------------------------------------

    /**
     * @return list<AssessmentSection>
     */
    public function sectionsFor(string $tenantId, string $assessmentId): array;

    public function findSection(string $tenantId, string $assessmentId, string $id): ?AssessmentSection;

    public function createSection(AssessmentSection $section): AssessmentSection;

    public function updateSection(AssessmentSection $section): AssessmentSection;

    /**
     * Removes a section. Its questions stay on the assessment, unfiled:
     * deleting a heading should not delete the work beneath it.
     */
    public function deleteSection(string $tenantId, string $assessmentId, string $id): bool;

    /**
     * @param list<string> $orderedIds
     */
    public function reorderSections(string $tenantId, string $assessmentId, array $orderedIds): int;

    /**
     * Files a question under a section, or unfiles it when null.
     */
    public function setQuestionSection(
        string $tenantId,
        string $assessmentId,
        string $questionId,
        ?string $sectionId,
    ): bool;

    // --- random rules -----------------------------------------------------

    /**
     * The rules, each carrying how many questions actually match it.
     *
     * @return list<RandomRule>
     */
    public function rulesFor(string $tenantId, string $assessmentId): array;

    public function createRule(RandomRule $rule): RandomRule;

    public function deleteRule(string $tenantId, string $assessmentId, string $id): bool;

    /**
     * Every question matching a rule, for the draw to sample from.
     *
     * @return list<Question>
     */
    public function candidatesForRule(string $tenantId, RandomRule $rule): array;
}
