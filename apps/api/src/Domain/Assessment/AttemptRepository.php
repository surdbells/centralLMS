<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * Storage for attempts and the answers within them.
 */
interface AttemptRepository
{
    /**
     * Every attempt this learner has made at this assessment, newest first.
     *
     * @return list<Attempt>
     */
    public function forLearner(string $tenantId, string $assessmentId, string $userId): array;

    /**
     * Every attempt one person has made, newest first.
     *
     * Across all assessments, which {@see forLearner} cannot answer — it
     * wants an assessment id, and a learner looking at their own record
     * does not have a list of the ones they have sat.
     *
     * @return list<Attempt>
     */
    public function mine(string $tenantId, string $userId, int $limit): array;

    /**
     * Attempts across learners, for the marking queue and reporting.
     *
     * @return array{items: list<Attempt>, total: int}
     */
    public function forAssessment(
        string $tenantId,
        string $assessmentId,
        int $page,
        int $perPage,
        ?AttemptStatus $status = null,
    ): array;

    /**
     * Every sitting anywhere in the organisation with an answer still
     * waiting on a marker, longest-waiting first.
     *
     * A marker had to pick an assessment before they could discover
     * whether it held any of their work, which is the wrong way round:
     * the question is "what have I got to mark?", not "is there anything
     * in this one?".
     *
     * `awaiting` on each row is the count of unmarked answers in it.
     *
     * @return array{items: list<array{attempt: Attempt, awaiting: int}>, total: int}
     */
    public function awaitingMarking(string $tenantId, int $page, int $perPage): array;

    public function find(string $tenantId, string $id): ?Attempt;

    /**
     * The learner's open sitting, if they have one.
     */
    public function findOpen(string $tenantId, string $assessmentId, string $userId): ?Attempt;

    /**
     * Opens a new attempt.
     *
     * The attempt number is chosen inside the same statement that inserts the
     * row, so two simultaneous starts cannot both become attempt 3 — the
     * unique index would reject the second, which is the point.
     */
    public function start(
        string $tenantId,
        string $assessmentId,
        string $userId,
        ?\DateTimeImmutable $expiresAt,
    ): Attempt;

    /**
     * Writes down the questions this attempt was dealt.
     *
     * Called once, at the start. Until random selection existed the paper
     * could be re-derived from the assessment on demand; it cannot now, so the
     * draw is persisted and every later read — resuming, scoring, marking —
     * uses this rather than the assessment.
     *
     * @param list<array{question: Question, pointsPossible: float, sectionId: ?string, sortOrder: int}> $paper
     */
    public function dealPaper(string $tenantId, string $attemptId, array $paper): void;

    /**
     * The questions this attempt was dealt, in order.
     *
     * @return list<array{question: Question, pointsPossible: float, sectionId: ?string, sortOrder: int}>
     */
    public function paperFor(string $tenantId, string $attemptId): array;

    /**
     * What the attempt is being marked out of, from its own paper.
     */
    public function paperTotal(string $tenantId, string $attemptId): float;

    /**
     * Saves one answer, replacing any previous answer to that question.
     *
     * @param array<string, mixed> $answer
     */
    public function saveAnswer(
        string $tenantId,
        Attempt $attempt,
        string $questionId,
        array $answer,
        GradeResult $grade,
        int $secondsSpent,
    ): void;

    /**
     * Answers so far, keyed by question id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function answersFor(string $tenantId, string $attemptId): array;

    /**
     * Closes an attempt and records its result.
     *
     * The totals are computed inside the transaction rather than passed in:
     * marks earned from `attempt_answers`, and the denominator from
     * `attempt_questions` — the paper this learner actually saw, which under
     * random selection is not the same as the assessment's own total.
     *
     * @param bool $autoSubmitted true when the clock ran out rather than the learner submitting
     */
    public function submit(string $tenantId, Attempt $attempt, Assessment $assessment, bool $autoSubmitted): Attempt;

    /**
     * Records a marker's decision on one answer and re-totals the attempt.
     *
     * @return array{attempt: Attempt, remaining: int} how many answers still await marking
     */
    public function gradeAnswer(
        string $tenantId,
        Attempt $attempt,
        Assessment $assessment,
        string $questionId,
        float $pointsEarned,
        ?string $feedback,
        string $markerId,
    ): array;

    /**
     * How many answers on this attempt still need a human.
     */
    public function ungradedCount(string $tenantId, string $attemptId): int;
}
