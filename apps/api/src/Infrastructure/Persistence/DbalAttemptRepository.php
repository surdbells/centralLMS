<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Assessment\Assessment;
use Central\Api\Domain\Assessment\Attempt;
use Central\Api\Domain\Assessment\AttemptRepository;
use Central\Api\Domain\Assessment\AttemptStatus;
use Central\Api\Domain\Assessment\GradeResult;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Assessment\QuestionRepository;
use Doctrine\DBAL\Connection;

final class DbalAttemptRepository implements AttemptRepository
{
    private const COLUMNS = 'at.id, at.tenant_id, at.assessment_id, at.user_id, at.attempt_number,
                             at.status, at.raw_score, at.max_score, at.percentage, at.passed,
                             at.time_spent_seconds, at.expires_at, at.started_at,
                             at.submitted_at, at.graded_at';

    private const JOINS = "
        LEFT JOIN assessments a ON a.id = at.assessment_id
        LEFT JOIN users u ON u.id = at.user_id
    ";

    private const EXTRA = "a.title AS assessment_title,
                           NULLIF(btrim(concat_ws(' ', u.first_name, u.last_name)), '') AS user_name";

    public function __construct(
        private readonly Connection $connection,
        private readonly QuestionRepository $questions,
    ) {
    }

    public function forLearner(string $tenantId, string $assessmentId, string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM attempts at %s
                 WHERE at.tenant_id = :tenant AND at.assessment_id = :assessment AND at.user_id = :user
                 ORDER BY at.attempt_number DESC',
                self::COLUMNS,
                self::EXTRA,
                self::JOINS,
            ),
            ['tenant' => $tenantId, 'assessment' => $assessmentId, 'user' => $userId],
        );

        return array_map(fn (array $r): Attempt => $this->hydrate($r), $rows);
    }

    public function forAssessment(
        string $tenantId,
        string $assessmentId,
        int $page,
        int $perPage,
        ?AttemptStatus $status = null,
    ): array {
        $where = 'at.tenant_id = :tenant AND at.assessment_id = :assessment';
        $params = ['tenant' => $tenantId, 'assessment' => $assessmentId];

        if ($status !== null) {
            $where .= ' AND at.status = :status';
            $params['status'] = $status->value;
        }

        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM attempts at WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM attempts at %s WHERE %s
                 ORDER BY at.submitted_at DESC NULLS LAST, at.started_at DESC
                 LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::EXTRA,
                self::JOINS,
                $where,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $r): Attempt => $this->hydrate($r), $rows),
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $id): ?Attempt
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM attempts at %s WHERE at.id = :id AND at.tenant_id = :tenant',
                self::COLUMNS,
                self::EXTRA,
                self::JOINS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findOpen(string $tenantId, string $assessmentId, string $userId): ?Attempt
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT %s, %s FROM attempts at %s
                 WHERE at.tenant_id = :tenant AND at.assessment_id = :assessment
                   AND at.user_id = :user AND at.status = 'in_progress'",
                self::COLUMNS,
                self::EXTRA,
                self::JOINS,
            ),
            ['tenant' => $tenantId, 'assessment' => $assessmentId, 'user' => $userId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function start(
        string $tenantId,
        string $assessmentId,
        string $userId,
        ?\DateTimeImmutable $expiresAt,
    ): Attempt {
        // The number is chosen inside the insert, so two simultaneous starts
        // race on the unique index rather than on a read-then-write. The loser
        // gets a constraint violation, which is the correct outcome — better
        // than two rows both calling themselves attempt 3.
        $id = $this->connection->fetchOne(
            'INSERT INTO attempts (tenant_id, assessment_id, user_id, attempt_number, expires_at)
             VALUES (
                 :tenant, :assessment, :user,
                 (SELECT COALESCE(MAX(attempt_number), 0) + 1
                  FROM attempts
                  WHERE assessment_id = :assessment AND user_id = :user),
                 :expires
             )
             RETURNING id',
            [
                'tenant'     => $tenantId,
                'assessment' => $assessmentId,
                'user'       => $userId,
                'expires'    => $expiresAt?->format(\DateTimeInterface::ATOM),
            ],
        );

        return $this->find($tenantId, (string) $id)
            ?? throw new \RuntimeException('Newly started attempt could not be read back.');
    }

    public function dealPaper(string $tenantId, string $attemptId, array $paper): void
    {
        if ($paper === []) {
            return;
        }

        $this->connection->transactional(function (Connection $tx) use ($tenantId, $attemptId, $paper): void {
            foreach ($paper as $line) {
                $tx->executeStatement(
                    'INSERT INTO attempt_questions
                         (tenant_id, attempt_id, question_id, section_id, points_possible, sort_order)
                     VALUES (:tenant, :attempt, :question, :section, :points, :sortOrder)
                     ON CONFLICT (attempt_id, question_id) DO NOTHING',
                    [
                        'tenant'    => $tenantId,
                        'attempt'   => $attemptId,
                        'question'  => $line['question']->id,
                        'section'   => $line['sectionId'],
                        'points'    => $line['pointsPossible'],
                        'sortOrder' => $line['sortOrder'],
                    ],
                );
            }
        });
    }

    public function paperFor(string $tenantId, string $attemptId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT question_id, section_id, points_possible, sort_order
             FROM attempt_questions
             WHERE attempt_id = :attempt AND tenant_id = :tenant
             ORDER BY sort_order ASC',
            ['attempt' => $attemptId, 'tenant' => $tenantId],
        );

        // One query for every question on the paper, not one each.
        $questions = $this->questions->findQuestionsByIds(
            $tenantId,
            array_map(static fn (array $r): string => (string) $r['question_id'], $rows),
        );

        $paper = [];

        foreach ($rows as $row) {
            $question = $questions[(string) $row['question_id']] ?? null;

            if ($question === null) {
                // Deleted from its bank mid-sitting. Skipped rather than
                // fatal, exactly as on the assessment itself.
                continue;
            }

            $paper[] = [
                'question'       => $question,
                'pointsPossible' => (float) $row['points_possible'],
                'sectionId'      => is_string($row['section_id'] ?? null) ? $row['section_id'] : null,
                'sortOrder'      => (int) $row['sort_order'],
            ];
        }

        return $paper;
    }

    public function paperTotal(string $tenantId, string $attemptId): float
    {
        return (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(points_possible), 0) FROM attempt_questions
             WHERE attempt_id = :attempt AND tenant_id = :tenant',
            ['attempt' => $attemptId, 'tenant' => $tenantId],
        );
    }

    public function saveAnswer(
        string $tenantId,
        Attempt $attempt,
        string $questionId,
        array $answer,
        GradeResult $grade,
        int $secondsSpent,
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO attempt_answers
                 (tenant_id, attempt_id, user_id, question_id, answer,
                  is_correct, points_earned, points_possible, time_spent_seconds)
             VALUES (:tenant, :attempt, :user, :question, :answer::jsonb,
                     :correct, :earned, :possible, :seconds)
             ON CONFLICT (attempt_id, question_id) DO UPDATE SET
                 answer = EXCLUDED.answer,
                 is_correct = EXCLUDED.is_correct,
                 points_earned = EXCLUDED.points_earned,
                 points_possible = EXCLUDED.points_possible,
                 -- Time accumulates: a learner returning to a question spent
                 -- both visits on it.
                 time_spent_seconds = attempt_answers.time_spent_seconds + EXCLUDED.time_spent_seconds,
                 answered_at = NOW(),
                 updated_at = NOW()',
            [
                'tenant'   => $tenantId,
                'attempt'  => $attempt->id,
                'user'     => $attempt->userId,
                'question' => $questionId,
                'answer'   => json_encode($answer, JSON_THROW_ON_ERROR),
                // An answer awaiting a human is stored as not-yet-correct with
                // no points; the marker sets both later.
                'correct'  => $grade->needsHumanGrading ? null : ($grade->isCorrect ? 'true' : 'false'),
                'earned'   => $grade->needsHumanGrading ? null : $grade->pointsEarned,
                'possible' => $grade->pointsPossible,
                'seconds'  => max(0, $secondsSpent),
            ],
        );
    }

    public function answersFor(string $tenantId, string $attemptId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT question_id, answer, is_correct, points_earned, points_possible,
                    feedback, graded_at, time_spent_seconds
             FROM attempt_answers
             WHERE attempt_id = :attempt AND tenant_id = :tenant',
            ['attempt' => $attemptId, 'tenant' => $tenantId],
        );

        $out = [];

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['answer'], true);

            $out[(string) $row['question_id']] = [
                'answer'         => is_array($decoded) ? $decoded : [],
                'isCorrect'      => $row['is_correct'] === null ? null : (bool) $row['is_correct'],
                'pointsEarned'   => $row['points_earned'] === null ? null : (float) $row['points_earned'],
                'pointsPossible' => (float) $row['points_possible'],
                'feedback'       => is_string($row['feedback'] ?? null) ? $row['feedback'] : null,
                'isGraded'       => $row['graded_at'] !== null || $row['is_correct'] !== null,
                'timeSpentSeconds' => (int) $row['time_spent_seconds'],
            ];
        }

        return $out;
    }

    public function submit(string $tenantId, Attempt $attempt, Assessment $assessment, bool $autoSubmitted): Attempt
    {
        /** @var Attempt $result */
        $result = $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $attempt, $assessment, $autoSubmitted): Attempt {
                // Totals come from the stored answers rather than from the
                // caller, so a submitted score is always the sum of what is
                // actually on the attempt.
                $totals = $tx->fetchAssociative(
                    'SELECT COALESCE(SUM(points_earned), 0) AS earned,
                            COALESCE(SUM(points_possible), 0) AS possible,
                            COUNT(*) FILTER (WHERE points_earned IS NULL) AS ungraded
                     FROM attempt_answers
                     WHERE attempt_id = :attempt AND tenant_id = :tenant',
                    ['attempt' => $attempt->id, 'tenant' => $tenantId],
                );

                $earned = (float) ($totals['earned'] ?? 0);
                $ungraded = (int) ($totals['ungraded'] ?? 0);

                // The denominator is the paper this learner was dealt, not
                // the assessment's own total: under random selection they
                // are different numbers, and skipping a question must
                // still cost its marks.
                $possible = $this->paperTotal($tenantId, $attempt->id);

                if ($possible <= 0.0) {
                    // No paper recorded — an attempt from before this
                    // table existed, or one dealt nothing. Fall back to
                    // what was answered rather than dividing by zero.
                    $possible = (float) ($totals['possible'] ?? 0);
                }

                // An attempt with unmarked answers is not graded yet, so it
                // gets no score and no pass — the check constraint enforces
                // that pairing, and it is the honest state.
                if ($ungraded > 0) {
                    $tx->executeStatement(
                        "UPDATE attempts
                         SET status = 'grading', submitted_at = NOW(),
                             time_spent_seconds = :seconds, updated_at = NOW()
                         WHERE id = :id AND tenant_id = :tenant",
                        [
                            'seconds' => $this->elapsed($attempt),
                            'id'      => $attempt->id,
                            'tenant'  => $tenantId,
                        ],
                    );
                } else {
                    $percentage = $possible > 0 ? round($earned / $possible * 100, 2) : 0.0;

                    $tx->executeStatement(
                        "UPDATE attempts
                         SET status = 'graded',
                             raw_score = :earned, max_score = :possible, percentage = :percentage,
                             passed = :passed,
                             submitted_at = NOW(), graded_at = NOW(),
                             time_spent_seconds = :seconds, updated_at = NOW()
                         WHERE id = :id AND tenant_id = :tenant",
                        [
                            'earned'     => $earned,
                            'possible'   => $possible,
                            'percentage' => $percentage,
                            // Stamped once, from the pass mark as it stands
                            // now. Recomputing later would let an author turn
                            // a historical pass into a fail by editing it.
                            'passed'     => $assessment->isPassed($earned, $possible) ? 'true' : 'false',
                            'seconds'    => $this->elapsed($attempt),
                            'id'         => $attempt->id,
                            'tenant'     => $tenantId,
                        ],
                    );
                }

                // Recorded after the fact so the row is consistent first.
                if ($autoSubmitted) {
                    $tx->executeStatement(
                        "UPDATE attempts SET status = 'auto_submitted'
                         WHERE id = :id AND tenant_id = :tenant AND status = 'graded'",
                        ['id' => $attempt->id, 'tenant' => $tenantId],
                    );
                }

                return $this->find($tenantId, $attempt->id)
                    ?? throw new \RuntimeException('Submitted attempt could not be read back.');
            },
        );

        return $result;
    }

    public function gradeAnswer(
        string $tenantId,
        Attempt $attempt,
        Assessment $assessment,
        string $questionId,
        float $pointsEarned,
        ?string $feedback,
        string $markerId,
    ): array {
        /** @var array{attempt: Attempt, remaining: int} $result */
        $result = $this->connection->transactional(
            function (Connection $tx) use (
                $tenantId,
                $attempt,
                $assessment,
                $questionId,
                $pointsEarned,
                $feedback,
                $markerId,
            ): array {
                $affected = $tx->executeStatement(
                    'UPDATE attempt_answers
                     SET points_earned = :earned,
                         -- Full marks reads as correct; anything less does not.
                         is_correct = (:earned2 >= points_possible AND points_possible > 0),
                         feedback = :feedback,
                         graded_by = :marker,
                         graded_at = NOW(),
                         updated_at = NOW()
                     WHERE attempt_id = :attempt AND question_id = :question AND tenant_id = :tenant',
                    [
                        'earned'   => $pointsEarned,
                        'earned2'  => $pointsEarned,
                        'feedback' => $feedback,
                        'marker'   => $markerId,
                        'attempt'  => $attempt->id,
                        'question' => $questionId,
                        'tenant'   => $tenantId,
                    ],
                );

                if ($affected === 0) {
                    throw new \RuntimeException('No such answer on this attempt.');
                }

                $remaining = $this->ungradedWithin($tx, $tenantId, $attempt->id);

                if ($remaining === 0) {
                    $totals = $tx->fetchAssociative(
                        'SELECT COALESCE(SUM(points_earned), 0) AS earned
                         FROM attempt_answers WHERE attempt_id = :attempt AND tenant_id = :tenant',
                        ['attempt' => $attempt->id, 'tenant' => $tenantId],
                    );

                    $earned = (float) ($totals['earned'] ?? 0);
                    $possible = $this->paperTotal($tenantId, $attempt->id);
                    $percentage = $possible > 0 ? round($earned / $possible * 100, 2) : 0.0;

                    $tx->executeStatement(
                        "UPDATE attempts
                         SET status = 'graded', raw_score = :earned, max_score = :possible,
                             percentage = :percentage, passed = :passed,
                             graded_at = NOW(), graded_by = :marker, updated_at = NOW()
                         WHERE id = :id AND tenant_id = :tenant",
                        [
                            'earned'     => $earned,
                            'possible'   => $possible,
                            'percentage' => $percentage,
                            'passed'     => $assessment->isPassed($earned, $possible) ? 'true' : 'false',
                            'marker'     => $markerId,
                            'id'         => $attempt->id,
                            'tenant'     => $tenantId,
                        ],
                    );
                }

                return [
                    'attempt' => $this->find($tenantId, $attempt->id)
                        ?? throw new \RuntimeException('Attempt could not be read back after grading.'),
                    'remaining' => $remaining,
                ];
            },
        );

        return $result;
    }

    public function ungradedCount(string $tenantId, string $attemptId): int
    {
        return $this->ungradedWithin($this->connection, $tenantId, $attemptId);
    }

    // --- internals --------------------------------------------------------

    private function ungradedWithin(Connection $connection, string $tenantId, string $attemptId): int
    {
        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM attempt_answers
             WHERE attempt_id = :attempt AND tenant_id = :tenant AND points_earned IS NULL',
            ['attempt' => $attemptId, 'tenant' => $tenantId],
        );
    }

    /**
     * How long the sitting lasted, from its own timestamps.
     *
     * Taken from the server clock rather than the browser's: a client-reported
     * duration on a timed exam is a number the person being timed controls.
     */
    private function elapsed(Attempt $attempt): int
    {
        if ($attempt->startedAt === null) {
            return $attempt->timeSpentSeconds;
        }

        $now = new \DateTimeImmutable();
        $end = $attempt->expiresAt !== null && $now > $attempt->expiresAt ? $attempt->expiresAt : $now;

        return max(0, $end->getTimestamp() - $attempt->startedAt->getTimestamp());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Attempt
    {
        $status = AttemptStatus::tryFrom((string) $row['status']);

        if ($status === null) {
            throw new \RuntimeException(sprintf('Unknown attempt status "%s".', $row['status']));
        }

        return new Attempt(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            assessmentId: (string) $row['assessment_id'],
            userId: (string) $row['user_id'],
            attemptNumber: (int) $row['attempt_number'],
            status: $status,
            rawScore: isset($row['raw_score']) ? (float) $row['raw_score'] : null,
            maxScore: isset($row['max_score']) ? (float) $row['max_score'] : null,
            percentage: isset($row['percentage']) ? (float) $row['percentage'] : null,
            passed: $row['passed'] === null ? null : (bool) $row['passed'],
            timeSpentSeconds: (int) $row['time_spent_seconds'],
            expiresAt: $this->date($row['expires_at'] ?? null),
            startedAt: $this->date($row['started_at'] ?? null),
            submittedAt: $this->date($row['submitted_at'] ?? null),
            gradedAt: $this->date($row['graded_at'] ?? null),
            assessmentTitle: is_string($row['assessment_title'] ?? null) ? $row['assessment_title'] : null,
            userName: is_string($row['user_name'] ?? null) ? $row['user_name'] : null,
        );
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
