<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Assessment\Assessment;
use Central\Api\Domain\Assessment\AssessmentRepository;
use Central\Api\Domain\Assessment\AssessmentStatus;
use Central\Api\Domain\Assessment\PassingType;
use Central\Api\Domain\Assessment\ProctoringLevel;
use Central\Api\Domain\Assessment\QuestionRepository;
use Central\Api\Domain\Assessment\QuestionSelectionMode;
use Central\Api\Domain\Assessment\RevealAnswers;
use Central\Api\Domain\Shared\Difficulty;
use Doctrine\DBAL\Connection;

final class DbalAssessmentRepository implements AssessmentRepository
{
    use DbalSectionAndRuleTrait;

    private const COLUMNS = 'a.id, a.tenant_id, a.title, a.description, a.instructions, a.kind, a.status,
                             a.difficulty, a.course_id, a.module_id, a.category_id,
                             a.time_limit_minutes, a.available_from, a.available_until,
                             a.grace_period_minutes, a.max_attempts, a.attempts_gap_hours,
                             a.passing_score, a.passing_type, a.question_selection,
                             a.shuffle_questions, a.shuffle_options, a.reveal_answers,
                             a.show_score_immediately, a.show_explanations, a.allow_backward_navigation,
                             a.requires_manual_grading, a.proctoring_level, a.random_question_count, a.created_by,
                             a.published_at, a.created_at, a.updated_at';

    /**
     * The question count and total points, computed rather than stored.
     *
     * The legacy `total_questions` column disagreed with reality in 16 of its
     * 19 rows; `total_points` was in the same family. Both are one subquery.
     */
    private const DERIVED = '(
            SELECT COUNT(*) FROM assessment_questions aq WHERE aq.assessment_id = a.id
        ) AS question_count,
        COALESCE((
            SELECT SUM(COALESCE(aq.points_override, q.points))
            FROM assessment_questions aq
            JOIN questions q ON q.id = aq.question_id
            WHERE aq.assessment_id = a.id
        ), 0) AS total_points';

    public function __construct(
        private readonly Connection $connection,
        private readonly QuestionRepository $questions,
    ) {
    }

    public function paginate(
        string $tenantId,
        int $page,
        int $perPage,
        ?string $search = null,
        ?AssessmentStatus $status = null,
        ?string $courseId = null,
    ): array {
        $where = 'a.tenant_id = :tenant AND a.deleted_at IS NULL';
        $params = ['tenant' => $tenantId];

        if ($status !== null) {
            $where .= ' AND a.status = :status';
            $params['status'] = $status->value;
        }

        if ($courseId !== null && $courseId !== '') {
            $where .= ' AND a.course_id = :course';
            $params['course'] = $courseId;
        }

        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (a.title ILIKE :search OR a.description ILIKE :search)';
            $params['search'] = '%' . trim($search) . '%';
        }

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM assessments a WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM assessments a WHERE %s
                 ORDER BY a.title ASC LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::DERIVED,
                $where,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $r): Assessment => $this->hydrate($r), $rows),
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $id): ?Assessment
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM assessments a
                 WHERE a.id = :id AND a.tenant_id = :tenant AND a.deleted_at IS NULL',
                self::COLUMNS,
                self::DERIVED,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(Assessment $assessment): Assessment
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO assessments (tenant_id, title, description, instructions, kind, status,
                                      difficulty, course_id, module_id, category_id,
                                      time_limit_minutes, available_from, available_until,
                                      grace_period_minutes, max_attempts, attempts_gap_hours,
                                      passing_score, passing_type, question_selection,
                                      shuffle_questions, shuffle_options, reveal_answers,
                                      show_score_immediately, show_explanations,
                                      allow_backward_navigation, random_question_count, created_by)
             VALUES (:tenant, :title, :description, :instructions, :kind, :status,
                     :difficulty, :course, :module, :category,
                     :timeLimit, :availableFrom, :availableUntil,
                     :grace, :maxAttempts, :gap,
                     :passingScore, :passingType, :selection,
                     :shuffleQuestions, :shuffleOptions, :reveal,
                     :showScore, :showExplanations, :allowBack, :randomCount, :createdBy)
             RETURNING id',
            $this->writeParams($assessment) + ['createdBy' => $assessment->createdBy],
        );

        return $this->find($assessment->tenantId, (string) $id)
            ?? throw new \RuntimeException('Newly created assessment could not be read back.');
    }

    public function update(Assessment $assessment): Assessment
    {
        $this->connection->executeStatement(
            'UPDATE assessments SET
                title = :title, description = :description, instructions = :instructions,
                kind = :kind, difficulty = :difficulty, course_id = :course, module_id = :module,
                category_id = :category, time_limit_minutes = :timeLimit,
                available_from = :availableFrom, available_until = :availableUntil,
                grace_period_minutes = :grace, max_attempts = :maxAttempts, attempts_gap_hours = :gap,
                passing_score = :passingScore, passing_type = :passingType,
                question_selection = :selection, shuffle_questions = :shuffleQuestions,
                shuffle_options = :shuffleOptions, reveal_answers = :reveal,
                show_score_immediately = :showScore, show_explanations = :showExplanations,
                allow_backward_navigation = :allowBack, random_question_count = :randomCount,
                updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            // Status excluded: publishing goes through setStatus().
            array_diff_key($this->writeParams($assessment), ['status' => null]) + ['id' => $assessment->id],
        );

        return $this->find($assessment->tenantId, $assessment->id)
            ?? throw new \RuntimeException('Updated assessment could not be read back.');
    }

    public function setStatus(string $tenantId, string $id, AssessmentStatus $status): ?Assessment
    {
        $affected = $this->connection->executeStatement(
            "UPDATE assessments
             SET status = :status,
                 published_at = CASE
                     WHEN :status2 = 'published' AND published_at IS NULL THEN NOW()
                     ELSE published_at
                 END,
                 updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL",
            ['status' => $status->value, 'status2' => $status->value, 'id' => $id, 'tenant' => $tenantId],
        );

        return $affected > 0 ? $this->find($tenantId, $id) : null;
    }

    public function softDelete(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            "UPDATE assessments SET deleted_at = NOW(), status = 'archived', updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL",
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function questionsFor(string $tenantId, string $assessmentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT question_id, points_override, sort_order, is_required, section_id
             FROM assessment_questions
             WHERE assessment_id = :assessment AND tenant_id = :tenant
             ORDER BY sort_order ASC',
            ['assessment' => $assessmentId, 'tenant' => $tenantId],
        );

        // One query for every question on the assessment, not one each.
        $questions = $this->questions->findQuestionsByIds(
            $tenantId,
            array_map(static fn (array $r): string => (string) $r['question_id'], $rows),
        );

        $out = [];

        foreach ($rows as $row) {
            $question = $questions[(string) $row['question_id']] ?? null;

            if ($question === null) {
                // Soft-deleted from its bank while still attached. Skipped
                // rather than fatal: an author removing a question should not
                // break every assessment that used it mid-sitting.
                continue;
            }

            $out[] = [
                'question'       => $question,
                'pointsOverride' => isset($row['points_override']) ? (float) $row['points_override'] : null,
                'sortOrder'      => (int) $row['sort_order'],
                'isRequired'     => (bool) $row['is_required'],
                'sectionId'      => is_string($row['section_id'] ?? null) ? $row['section_id'] : null,
            ];
        }

        return $out;
    }

    public function attachQuestion(
        string $tenantId,
        string $assessmentId,
        string $questionId,
        ?float $pointsOverride,
        ?int $sortOrder,
        bool $isRequired,
    ): bool {
        $affected = $this->connection->executeStatement(
            'INSERT INTO assessment_questions
                 (tenant_id, assessment_id, question_id, points_override, sort_order, is_required)
             VALUES (
                 :tenant, :assessment, :question, :points,
                 COALESCE(:sortOrder, (
                     SELECT COALESCE(MAX(sort_order), -1) + 1
                     FROM assessment_questions
                     WHERE assessment_id = :assessment
                 )),
                 :required
             )
             ON CONFLICT (assessment_id, question_id) DO NOTHING',
            [
                'tenant'     => $tenantId,
                'assessment' => $assessmentId,
                'question'   => $questionId,
                'points'     => $pointsOverride,
                'sortOrder'  => $sortOrder,
                'required'   => $isRequired ? 'true' : 'false',
            ],
        );

        if ($affected > 0) {
            $this->refreshManualGradingFlag($tenantId, $assessmentId);
        }

        return $affected > 0;
    }

    public function detachQuestion(string $tenantId, string $assessmentId, string $questionId): bool
    {
        $affected = $this->connection->executeStatement(
            'DELETE FROM assessment_questions
             WHERE assessment_id = :assessment AND question_id = :question AND tenant_id = :tenant',
            ['assessment' => $assessmentId, 'question' => $questionId, 'tenant' => $tenantId],
        );

        if ($affected > 0) {
            $this->refreshManualGradingFlag($tenantId, $assessmentId);
        }

        return $affected > 0;
    }

    public function reorderQuestions(string $tenantId, string $assessmentId, array $orderedQuestionIds): int
    {
        if ($orderedQuestionIds === []) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'UPDATE assessment_questions AS aq
             SET sort_order = new_order.position
             FROM (SELECT * FROM unnest(:ids::uuid[]) WITH ORDINALITY AS t(id, position)) AS new_order
             WHERE aq.question_id = new_order.id
               AND aq.assessment_id = :assessment
               AND aq.tenant_id = :tenant',
            [
                'ids'        => '{' . implode(',', $orderedQuestionIds) . '}',
                'assessment' => $assessmentId,
                'tenant'     => $tenantId,
            ],
        );
    }

    public function assessmentIdsUsingQuestion(string $tenantId, string $questionId): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT assessment_id FROM assessment_questions
             WHERE question_id = :question AND tenant_id = :tenant',
            ['question' => $questionId, 'tenant' => $tenantId],
        );

        return $ids;
    }

    public function refreshManualGradingFlag(string $tenantId, string $assessmentId): void
    {
        // The types no machine can mark, named here rather than inferred, so
        // adding a type to the enum does not silently change grading routing.
        $this->connection->executeStatement(
            "UPDATE assessments SET requires_manual_grading = EXISTS (
                 SELECT 1 FROM assessment_questions aq
                 JOIN questions q ON q.id = aq.question_id
                 WHERE aq.assessment_id = :id
                   AND q.type IN ('long_answer', 'file_upload', 'hotspot')
             ), updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant",
            ['id' => $assessmentId, 'tenant' => $tenantId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function writeParams(Assessment $a): array
    {
        return [
            'tenant'           => $a->tenantId,
            'title'            => $a->title,
            'description'      => $a->description,
            'instructions'     => $a->instructions,
            'kind'             => $a->kind,
            'status'           => $a->status->value,
            'difficulty'       => $a->difficulty?->value,
            'course'           => $a->courseId,
            'module'           => $a->moduleId,
            'category'         => $a->categoryId,
            'timeLimit'        => $a->timeLimitMinutes,
            'availableFrom'    => $a->availableFrom?->format(\DateTimeInterface::ATOM),
            'availableUntil'   => $a->availableUntil?->format(\DateTimeInterface::ATOM),
            'grace'            => $a->gracePeriodMinutes,
            'maxAttempts'      => $a->maxAttempts,
            'gap'              => $a->attemptsGapHours,
            'passingScore'     => $a->passingScore,
            'passingType'      => $a->passingType->value,
            'selection'        => $a->questionSelection->value,
            'shuffleQuestions' => $a->shuffleQuestions ? 'true' : 'false',
            'shuffleOptions'   => $a->shuffleOptions ? 'true' : 'false',
            'reveal'           => $a->revealAnswers->value,
            'showScore'        => $a->showScoreImmediately ? 'true' : 'false',
            'showExplanations' => $a->showExplanations ? 'true' : 'false',
            'allowBack'        => $a->allowBackwardNavigation ? 'true' : 'false',
            'randomCount'      => $a->randomQuestionCount,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Assessment
    {
        $status = AssessmentStatus::tryFrom((string) $row['status']);

        if ($status === null) {
            throw new \RuntimeException(sprintf('Unknown assessment status "%s".', $row['status']));
        }

        $difficulty = $this->text($row['difficulty'] ?? null);

        return new Assessment(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            title: (string) $row['title'],
            status: $status,
            kind: (string) $row['kind'],
            description: $this->text($row['description'] ?? null),
            instructions: $this->text($row['instructions'] ?? null),
            difficulty: $difficulty === null ? null : Difficulty::tryFrom($difficulty),
            courseId: $this->text($row['course_id'] ?? null),
            moduleId: $this->text($row['module_id'] ?? null),
            categoryId: $this->text($row['category_id'] ?? null),
            timeLimitMinutes: isset($row['time_limit_minutes']) ? (int) $row['time_limit_minutes'] : null,
            availableFrom: $this->date($row['available_from'] ?? null),
            availableUntil: $this->date($row['available_until'] ?? null),
            gracePeriodMinutes: (int) $row['grace_period_minutes'],
            maxAttempts: isset($row['max_attempts']) ? (int) $row['max_attempts'] : null,
            attemptsGapHours: (int) $row['attempts_gap_hours'],
            passingScore: (float) $row['passing_score'],
            passingType: PassingType::tryFrom((string) $row['passing_type']) ?? PassingType::Percentage,
            questionSelection: QuestionSelectionMode::tryFrom((string) $row['question_selection'])
                ?? QuestionSelectionMode::Manual,
            randomQuestionCount: isset($row['random_question_count'])
                ? (int) $row['random_question_count']
                : null,
            shuffleQuestions: (bool) $row['shuffle_questions'],
            shuffleOptions: (bool) $row['shuffle_options'],
            revealAnswers: RevealAnswers::tryFrom((string) $row['reveal_answers'])
                ?? RevealAnswers::AfterAllAttempts,
            showScoreImmediately: (bool) $row['show_score_immediately'],
            showExplanations: (bool) $row['show_explanations'],
            allowBackwardNavigation: (bool) $row['allow_backward_navigation'],
            requiresManualGrading: (bool) $row['requires_manual_grading'],
            proctoringLevel: ProctoringLevel::tryFrom((string) $row['proctoring_level']) ?? ProctoringLevel::None,
            createdBy: $this->text($row['created_by'] ?? null),
            publishedAt: $this->date($row['published_at'] ?? null),
            createdAt: $this->date($row['created_at'] ?? null),
            updatedAt: $this->date($row['updated_at'] ?? null),
            questionCount: (int) ($row['question_count'] ?? 0),
            totalPoints: (float) ($row['total_points'] ?? 0),
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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
