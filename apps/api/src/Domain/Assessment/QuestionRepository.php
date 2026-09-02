<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * Storage for question banks and the questions inside them.
 */
interface QuestionRepository
{
    /**
     * @return array{items: list<QuestionBank>, total: int}
     */
    public function paginateBanks(string $tenantId, int $page, int $perPage, ?string $search = null): array;

    public function findBank(string $tenantId, string $id): ?QuestionBank;

    public function createBank(QuestionBank $bank): QuestionBank;

    public function updateBank(QuestionBank $bank): QuestionBank;

    public function softDeleteBank(string $tenantId, string $id): bool;

    public function bankHasName(string $tenantId, string $name, ?string $exceptId = null): bool;

    /**
     * Questions in a bank, each with its options.
     *
     * @return array{items: list<Question>, total: int}
     */
    public function paginateQuestions(
        string $tenantId,
        string $bankId,
        int $page,
        int $perPage,
        ?string $search = null,
        ?QuestionType $type = null,
        ?string $tag = null,
    ): array;

    public function findQuestion(string $tenantId, string $id): ?Question;

    /**
     * Loads many questions at once, keyed by id.
     *
     * Assembling an assessment means fetching every question on it; doing that
     * one at a time is the N+1 the legacy screens were made of.
     *
     * @param list<string> $ids
     *
     * @return array<string, Question>
     */
    public function findQuestionsByIds(string $tenantId, array $ids): array;

    /**
     * Writes the question and replaces its options in one transaction.
     */
    public function createQuestion(Question $question): Question;

    /**
     * Replaces the options wholesale rather than diffing them.
     *
     * Diffing would need stable client-side ids for options that may not have
     * any yet. Replacing is safe because an option carries no history of its
     * own — an answer records what was chosen in `attempt_answers.answer`,
     * which is a snapshot, not a live reference.
     */
    public function updateQuestion(Question $question): Question;

    public function softDeleteQuestion(string $tenantId, string $id): bool;

    /**
     * Moves a question to another bank of the same tenant.
     *
     * The separate operation `updateQuestion` deliberately refuses, because
     * changing a question's bank changes what every assessment drawing on those
     * banks contains; this is the explicit, deliberate way to do it.
     */
    public function moveQuestion(string $tenantId, string $questionId, string $fromBankId, string $toBankId): bool;

    /**
     * The distinct tags used across a bank's live questions, alphabetically.
     *
     * @return list<string>
     */
    public function distinctTags(string $tenantId, string $bankId): array;

    /**
     * Per-bank counts: total, active, and a breakdown by type and difficulty.
     *
     * @return array{
     *     total: int, active: int,
     *     byType: list<array{type: string, count: int}>,
     *     byDifficulty: list<array{difficulty: string, count: int}>
     * }
     */
    public function bankStatistics(string $tenantId, string $bankId): array;

    /**
     * Tenant-wide counts across every bank.
     *
     * @return array{
     *     totalQuestions: int, activeQuestions: int, totalBanks: int,
     *     byType: list<array{type: string, count: int}>,
     *     byDifficulty: list<array{difficulty: string, count: int}>
     * }
     */
    public function overallStatistics(string $tenantId): array;

    /**
     * Whether the question is used by any assessment.
     *
     * Deleting a question that assessments still ask is how a live quiz loses
     * a question mid-term, so the caller checks first.
     */
    public function questionIsInUse(string $tenantId, string $id): bool;
}
