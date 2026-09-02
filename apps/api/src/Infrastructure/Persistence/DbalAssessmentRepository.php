<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Assessment\Assessment;
use Central\Api\Domain\Assessment\AssessmentRepository;
use Central\Api\Domain\Assessment\AssessmentStatus;
use Central\Api\Domain\Assessment\LearnerAssessment;
use Central\Api\Domain\Assessment\PassingType;
use Central\Api\Domain\Assessment\ProctoringLevel;
use Central\Api\Domain\Assessment\QuestionRepository;
use Central\Api\Domain\Assessment\QuestionSelectionMode;
use Central\Api\Domain\Assessment\RevealAnswers;
use Central\Api\Domain\Shared\Difficulty;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;

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
                             a.training_id, a.welcome_message, a.completion_message,
                             a.negative_marking, a.negative_marking_percentage, a.partial_credit,
                             a.questions_per_page, a.show_question_numbers, a.show_progress_bar, a.grading_scale_id,
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

    /**
     * How an assessment is doing, for the listing cards. One correlated pass
     * over the attempts: how many were sat, how many finished, the share that
     * passed and the average score. A completion is anything that reached a
     * terminal state; the pass rate and average are over graded attempts only,
     * because an ungraded attempt has no verdict yet.
     */
    private const STATS = "(
            SELECT COUNT(*) FROM attempts t WHERE t.assessment_id = a.id
        ) AS total_attempts,
        (
            SELECT COUNT(*) FROM attempts t
            WHERE t.assessment_id = a.id AND t.status IN ('submitted', 'auto_submitted', 'grading', 'graded')
        ) AS total_completions,
        COALESCE((
            SELECT ROUND(100.0 * COUNT(*) FILTER (WHERE t.passed) / NULLIF(COUNT(*) FILTER (WHERE t.status = 'graded'), 0), 1)
            FROM attempts t WHERE t.assessment_id = a.id
        ), 0) AS pass_rate,
        COALESCE((
            SELECT ROUND(AVG(t.percentage) FILTER (WHERE t.status = 'graded'), 1)
            FROM attempts t WHERE t.assessment_id = a.id
        ), 0) AS avg_score";

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
        ?string $kind = null,
        ?string $proctoringLevel = null,
        ?string $categoryId = null,
        ?string $sort = null,
        ?string $order = null,
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

        if ($kind !== null && $kind !== '') {
            $where .= ' AND a.kind = :kind';
            $params['kind'] = $kind;
        }

        if ($proctoringLevel !== null && $proctoringLevel !== '') {
            $where .= ' AND a.proctoring_level = :proctoring';
            $params['proctoring'] = $proctoringLevel;
        }

        if ($categoryId !== null && $categoryId !== '') {
            $where .= ' AND a.category_id = :category';
            $params['category'] = $categoryId;
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

        // Only these may be sorted on, and the direction is boolean — the
        // field arrives from the browser, so nothing here is interpolated
        // that was not first checked against this list.
        $sortColumns = [
            'title'          => 'a.title',
            'created_at'     => 'a.created_at',
            'updated_at'     => 'a.updated_at',
            'total_attempts' => 'total_attempts',
            'pass_rate'      => 'pass_rate',
        ];
        $sortColumn = $sortColumns[$sort ?? ''] ?? 'a.title';
        $sortDir = strtolower((string) $order) === 'asc' ? 'ASC' : 'DESC';
        // Title reads naturally ascending; everything else defaults to newest
        // or highest first unless the caller says otherwise.
        if ($sort === null || $sort === '' || $sort === 'title') {
            $sortDir = strtolower((string) $order) === 'desc' ? 'DESC' : 'ASC';
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s, %s FROM assessments a WHERE %s
                 ORDER BY %s %s NULLS LAST LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::DERIVED,
                self::STATS,
                $where,
                $sortColumn,
                $sortDir,
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
                                      allow_backward_navigation, random_question_count,
                                      proctoring_level, training_id, welcome_message, completion_message,
                                      negative_marking, negative_marking_percentage, partial_credit,
                                      questions_per_page, show_question_numbers, show_progress_bar,
                                      grading_scale_id, created_by)
             VALUES (:tenant, :title, :description, :instructions, :kind, :status,
                     :difficulty, :course, :module, :category,
                     :timeLimit, :availableFrom, :availableUntil,
                     :grace, :maxAttempts, :gap,
                     :passingScore, :passingType, :selection,
                     :shuffleQuestions, :shuffleOptions, :reveal,
                     :showScore, :showExplanations, :allowBack, :randomCount,
                     :proctoring, :training, :welcome, :completion,
                     :negMarking, :negMarkingPct, :partialCredit,
                     :perPage, :showNumbers, :showProgress, :gradingScale, :createdBy)
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
                proctoring_level = :proctoring, training_id = :training,
                welcome_message = :welcome, completion_message = :completion,
                negative_marking = :negMarking, negative_marking_percentage = :negMarkingPct,
                partial_credit = :partialCredit, questions_per_page = :perPage,
                show_question_numbers = :showNumbers, show_progress_bar = :showProgress,
                grading_scale_id = :gradingScale,
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

    /**
     * The headline figures for the authoring list: how many assessments sit in
     * each status, and across all of them how many attempts have been made and
     * what share passed.
     *
     * @return array{total: int, published: int, draft: int, closed: int, totalAttempts: int, totalCompletions: int, avgPassRate: float}
     */
    public function stats(string $tenantId): array
    {
        $counts = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'published') AS published,
                    COUNT(*) FILTER (WHERE status = 'draft') AS draft,
                    COUNT(*) FILTER (WHERE status = 'closed') AS closed
             FROM assessments WHERE tenant_id = :tenant AND deleted_at IS NULL",
            ['tenant' => $tenantId],
        ) ?: [];

        // Attempts only count against assessments that still exist.
        $attempts = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS total_attempts,
                    COUNT(*) FILTER (WHERE t.status IN ('submitted', 'auto_submitted', 'grading', 'graded')) AS total_completions,
                    ROUND(100.0 * COUNT(*) FILTER (WHERE t.passed)
                          / NULLIF(COUNT(*) FILTER (WHERE t.status = 'graded'), 0), 1) AS avg_pass_rate
             FROM attempts t
             JOIN assessments a ON a.id = t.assessment_id AND a.deleted_at IS NULL
             WHERE t.tenant_id = :tenant",
            ['tenant' => $tenantId],
        ) ?: [];

        return [
            'total'            => (int) ($counts['total'] ?? 0),
            'published'        => (int) ($counts['published'] ?? 0),
            'draft'            => (int) ($counts['draft'] ?? 0),
            'closed'           => (int) ($counts['closed'] ?? 0),
            'totalAttempts'    => (int) ($attempts['total_attempts'] ?? 0),
            'totalCompletions' => (int) ($attempts['total_completions'] ?? 0),
            'avgPassRate'      => (float) ($attempts['avg_pass_rate'] ?? 0),
        ];
    }

    public function learnerReport(
        string $tenantId,
        ?string $assessmentId,
        ?string $categoryId,
        ?string $status,
        ?\DateTimeImmutable $since,
    ): array {
        $where = 'at.tenant_id = :tenant';
        $params = ['tenant' => $tenantId];

        if ($assessmentId !== null && $assessmentId !== '') {
            $where .= ' AND at.assessment_id = :assessment';
            $params['assessment'] = $assessmentId;
        }

        if ($categoryId !== null && $categoryId !== '') {
            $where .= ' AND a.category_id = :category';
            $params['category'] = $categoryId;
        }

        if ($since !== null) {
            $where .= ' AND at.started_at >= :since';
            $params['since'] = $since->format('Y-m-d H:i:sP');
        }

        // The per-learner status is derived in the inner query, then filtered in
        // the outer WHERE so the status filter can key off the aggregate.
        $outer = '';

        if ($status !== null && $status !== '') {
            $outer = ' WHERE rows.status = :statusFilter';
            $params['statusFilter'] = $status;
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM (
                SELECT u.id AS learner_id,
                       btrim(u.first_name || ' ' || u.last_name) AS learner_name,
                       u.email AS email,
                       dept.name AS department,
                       COUNT(at.id) AS attempts,
                       ROUND(COALESCE(MAX(at.percentage), 0), 1) AS best_score,
                       ROUND(COALESCE(AVG(at.percentage), 0), 1) AS avg_score,
                       ROUND(COALESCE(AVG(at.time_spent_seconds), 0)) AS time_spent_avg,
                       COALESCE(bool_or(at.passed), false) AS passed,
                       MAX(COALESCE(at.submitted_at, at.started_at)) AS last_attempt_date,
                       bool_or(EXISTS (
                           SELECT 1 FROM certificates c
                           WHERE c.tenant_id = at.tenant_id AND c.user_id = at.user_id
                             AND c.assessment_id = at.assessment_id AND c.status = 'active'
                       )) AS certificate_issued,
                       CASE WHEN bool_or(at.passed) THEN 'passed'
                            WHEN bool_or(at.status IN ('not_started', 'in_progress', 'grading')) THEN 'in_progress'
                            ELSE 'failed' END AS status
                FROM attempts at
                JOIN users u ON u.id = at.user_id AND u.tenant_id = at.tenant_id AND u.deleted_at IS NULL
                JOIN assessments a ON a.id = at.assessment_id AND a.tenant_id = at.tenant_id AND a.deleted_at IS NULL
                LEFT JOIN lookups dept ON dept.id = u.department_id AND dept.kind = 'department'
                WHERE {$where}
                GROUP BY u.id, u.first_name, u.last_name, u.email, dept.name
            ) rows{$outer}
            ORDER BY rows.learner_name",
            $params,
        );

        return array_map(
            static fn (array $r): array => [
                'learner_id'         => (string) $r['learner_id'],
                'learner_name'       => (string) $r['learner_name'],
                'email'              => (string) $r['email'],
                'department'         => $r['department'] !== null ? (string) $r['department'] : null,
                'attempts'           => (int) $r['attempts'],
                'best_score'         => (float) $r['best_score'],
                'avg_score'          => (float) $r['avg_score'],
                'time_spent_avg'     => (int) $r['time_spent_avg'],
                'passed'             => (bool) $r['passed'],
                'certificate_issued' => (bool) $r['certificate_issued'],
                'last_attempt_date'  => $r['last_attempt_date'] !== null
                    ? (new \DateTimeImmutable((string) $r['last_attempt_date']))->format(\DateTimeInterface::ATOM)
                    : null,
                'status'             => (string) $r['status'],
            ],
            $rows,
        );
    }

    public function itemAnalysis(string $tenantId, string $assessmentId): array
    {
        // Correctness credits a manually-graded answer awarded full marks, so a
        // graded essay counts as "correct" for the p-value and correlation.
        $corr = 'COALESCE(aa.is_correct, (aa.points_earned IS NOT NULL '
            . 'AND aa.points_possible > 0 AND aa.points_earned >= aa.points_possible))';
        $answered = "(aa.id IS NOT NULL AND aa.answer <> '{}'::jsonb)";

        $rows = $this->connection->fetchAllAssociative(
            "WITH scored AS (
                SELECT at.id AS attempt_id, at.percentage::float8 AS total_score
                FROM attempts at
                WHERE at.tenant_id = :tenant AND at.assessment_id = :assessment AND at.percentage IS NOT NULL
            )
            SELECT q.id AS question_id, q.text AS question_text, q.type AS question_type,
                   COUNT(aq.attempt_id) AS total_attempts,
                   COUNT(*) FILTER (WHERE {$answered}) AS answered_count,
                   COUNT(*) FILTER (WHERE {$corr}) AS correct_count,
                   COUNT(aq.attempt_id) - COUNT(*) FILTER (WHERE {$answered}) AS skipped_count,
                   ROUND(100.0 * COUNT(*) FILTER (WHERE {$corr}) / NULLIF(COUNT(aq.attempt_id), 0), 1) AS correct_rate,
                   ROUND((COUNT(*) FILTER (WHERE {$corr}))::numeric / NULLIF(COUNT(aq.attempt_id), 0), 4) AS difficulty_index,
                   ROUND(COALESCE(AVG(aa.time_spent_seconds) FILTER (WHERE aa.id IS NOT NULL), 0)) AS avg_time_seconds,
                   ROUND(COALESCE(corr(({$corr})::int::float8, s.total_score), 0)::numeric, 4) AS discrimination_index
            FROM scored s
            JOIN attempt_questions aq ON aq.attempt_id = s.attempt_id
            JOIN questions q ON q.id = aq.question_id AND q.tenant_id = :tenant
            LEFT JOIN attempt_answers aa
                ON aa.attempt_id = aq.attempt_id AND aa.question_id = aq.question_id AND aa.tenant_id = :tenant
            LEFT JOIN assessment_questions asq ON asq.assessment_id = :assessment AND asq.question_id = q.id
            GROUP BY q.id, q.text, q.type, asq.sort_order
            ORDER BY asq.sort_order NULLS LAST, q.text",
            ['tenant' => $tenantId, 'assessment' => $assessmentId],
        );

        return array_map(
            static fn (array $r): array => [
                'question_id'          => (string) $r['question_id'],
                'question_text'        => (string) $r['question_text'],
                'question_type'        => (string) $r['question_type'],
                'total_attempts'       => (int) $r['total_attempts'],
                'answered_count'       => (int) $r['answered_count'],
                'correct_count'        => (int) $r['correct_count'],
                'skipped_count'        => (int) $r['skipped_count'],
                'correct_rate'         => (float) ($r['correct_rate'] ?? 0),
                'difficulty_index'     => (float) ($r['difficulty_index'] ?? 0),
                'avg_time_seconds'     => (int) $r['avg_time_seconds'],
                'discrimination_index' => (float) ($r['discrimination_index'] ?? 0),
            ],
            $rows,
        );
    }

    public function dashboard(string $tenantId, \DateTimeImmutable $now, \DateTimeImmutable $windowFrom): array
    {
        $nowParam = $now->format('Y-m-d H:i:sP');
        $fromParam = $windowFrom->format('Y-m-d H:i:sP');
        $base = ['tenant' => $tenantId, 'now' => $nowParam, 'from' => $fromParam];

        $scalars = $this->connection->fetchAssociative(
            "SELECT COUNT(DISTINCT t.user_id) AS total_learners,
                    COUNT(DISTINCT t.user_id) FILTER (WHERE t.started_at >= :from) AS active_learners,
                    ROUND(AVG(t.percentage) FILTER (WHERE t.status = 'graded' AND t.started_at >= :from), 1) AS avg_score,
                    COUNT(*) FILTER (WHERE t.started_at >= date_trunc('month', :now::timestamptz)) AS attempts_this_month,
                    COUNT(*) FILTER (
                        WHERE t.started_at >= date_trunc('month', :now::timestamptz) - interval '1 month'
                          AND t.started_at < date_trunc('month', :now::timestamptz)
                    ) AS attempts_prev_month
             FROM attempts t
             JOIN assessments a ON a.id = t.assessment_id AND a.deleted_at IS NULL
             WHERE t.tenant_id = :tenant",
            $base,
        ) ?: [];

        $totalQuestions = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM questions WHERE tenant_id = :tenant AND deleted_at IS NULL AND is_active',
            ['tenant' => $tenantId],
        );

        $thisMonth = (int) ($scalars['attempts_this_month'] ?? 0);
        $prevMonth = (int) ($scalars['attempts_prev_month'] ?? 0);
        $changePercent = $prevMonth > 0
            ? (int) round(100 * ($thisMonth - $prevMonth) / $prevMonth)
            : ($thisMonth > 0 ? 100 : 0);

        $trends = $this->connection->fetchAllAssociative(
            "SELECT to_char(m.month, 'Mon') AS month,
                    COUNT(t.id) AS attempts,
                    COALESCE(ROUND(100.0 * COUNT(*) FILTER (WHERE t.passed)
                        / NULLIF(COUNT(*) FILTER (WHERE t.status = 'graded'), 0), 0), 0) AS pass_rate,
                    COALESCE(ROUND(AVG(t.percentage) FILTER (WHERE t.status = 'graded'), 0), 0) AS avg_score
             FROM generate_series(
                    date_trunc('month', :now::timestamptz) - interval '5 months',
                    date_trunc('month', :now::timestamptz),
                    interval '1 month'
                  ) AS m(month)
             LEFT JOIN attempts t
                ON date_trunc('month', t.started_at) = m.month AND t.tenant_id = :tenant
                AND EXISTS (SELECT 1 FROM assessments a WHERE a.id = t.assessment_id AND a.deleted_at IS NULL)
             GROUP BY m.month
             ORDER BY m.month",
            ['tenant' => $tenantId, 'now' => $nowParam],
        );

        $activityRows = $this->connection->fetchAllAssociative(
            "(SELECT 'att:' || t.id || ':submitted' AS id, 'attempt' AS type, t.submitted_at AS ts,
                     btrim(u.first_name || ' ' || u.last_name) AS user_name, a.title AS assessment_title
              FROM attempts t
              JOIN assessments a ON a.id = t.assessment_id AND a.deleted_at IS NULL
              JOIN users u ON u.id = t.user_id
              WHERE t.tenant_id = :tenant AND t.submitted_at IS NOT NULL)
             UNION ALL
             (SELECT 'att:' || t.id || ':graded', 'grading', t.graded_at,
                     btrim(u.first_name || ' ' || u.last_name), a.title
              FROM attempts t
              JOIN assessments a ON a.id = t.assessment_id AND a.deleted_at IS NULL
              JOIN users u ON u.id = t.user_id
              WHERE t.tenant_id = :tenant AND t.graded_at IS NOT NULL)
             UNION ALL
             (SELECT 'asm:' || a.id || ':published', 'publish', a.published_at, NULL, a.title
              FROM assessments a
              WHERE a.tenant_id = :tenant AND a.deleted_at IS NULL AND a.published_at IS NOT NULL)
             ORDER BY ts DESC NULLS LAST
             LIMIT 15",
            ['tenant' => $tenantId],
        );

        $difficulty = $this->connection->fetchAllAssociative(
            "SELECT q.difficulty AS difficulty,
                    COUNT(DISTINCT q.id) AS count,
                    COALESCE(ROUND(AVG(100.0 * aa.points_earned / NULLIF(aa.points_possible, 0))
                        FILTER (WHERE aa.graded_at IS NOT NULL AND aa.graded_at >= :from), 0), 0) AS avg_score,
                    COALESCE(ROUND(100.0 * COUNT(*) FILTER (WHERE aa.is_correct)
                        / NULLIF(COUNT(*) FILTER (WHERE aa.is_correct IS NOT NULL AND aa.graded_at >= :from), 0), 0), 0) AS pass_rate
             FROM questions q
             LEFT JOIN attempt_answers aa ON aa.question_id = q.id AND aa.tenant_id = :tenant
             WHERE q.tenant_id = :tenant AND q.deleted_at IS NULL AND q.is_active AND q.difficulty IS NOT NULL
             GROUP BY q.difficulty
             ORDER BY CASE q.difficulty
                 WHEN 'beginner' THEN 1 WHEN 'intermediate' THEN 2 WHEN 'professional' THEN 3 ELSE 4 END",
            ['tenant' => $tenantId, 'from' => $fromParam],
        );

        return [
            'stats' => [
                'attemptsThisMonth'     => $thisMonth,
                'attemptsChangePercent' => $changePercent,
                'avgScore'              => (float) ($scalars['avg_score'] ?? 0),
                'totalLearners'         => (int) ($scalars['total_learners'] ?? 0),
                'activeLearners'        => (int) ($scalars['active_learners'] ?? 0),
                'totalQuestions'        => $totalQuestions,
            ],
            'monthlyTrends' => array_map(
                static fn (array $r): array => [
                    'month'    => (string) $r['month'],
                    'attempts' => (int) $r['attempts'],
                    'passRate' => (int) $r['pass_rate'],
                    'avgScore' => (int) $r['avg_score'],
                ],
                $trends,
            ),
            'recentActivity' => array_map(
                fn (array $r): array => $this->activityRow($r),
                $activityRows,
            ),
            'difficultyBreakdown' => array_map(
                static fn (array $r): array => [
                    'difficulty' => (string) $r['difficulty'],
                    'count'      => (int) $r['count'],
                    'avgScore'   => (int) $r['avg_score'],
                    'passRate'   => (int) $r['pass_rate'],
                ],
                $difficulty,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function activityRow(array $r): array
    {
        $type = (string) $r['type'];
        $user = $r['user_name'] !== null ? (string) $r['user_name'] : null;
        $title = $r['assessment_title'] !== null ? (string) $r['assessment_title'] : null;

        $message = match ($type) {
            'attempt'  => trim(($user ?? 'Someone') . ' submitted ' . ($title ?? 'an assessment')),
            'grading'  => trim(($title ?? 'An assessment') . ' graded for ' . ($user ?? 'a learner')),
            'publish'  => trim(($title ?? 'An assessment') . ' was published'),
            default    => (string) ($title ?? ''),
        };

        return [
            'id'              => (string) $r['id'],
            'type'            => $type,
            'message'         => $message,
            'timestamp'       => $r['ts'] !== null
                ? (new \DateTimeImmutable((string) $r['ts']))->format(\DateTimeInterface::ATOM)
                : null,
            'userName'        => $user,
            'assessmentTitle' => $title,
        ];
    }

    public function catalogueForLearner(
        string $tenantId,
        string $userId,
        bool $assignedOnly = false,
        ?string $search = null,
        ?string $categoryId = null,
        ?string $kind = null,
        int $limit = 500,
    ): array {
        $where = "a.tenant_id = :tenant AND a.deleted_at IS NULL AND a.status = 'published'";
        $params = ['tenant' => $tenantId, 'user' => $userId];
        $types = ['limit' => ParameterType::INTEGER];

        if ($assignedOnly) {
            $where .= ' AND m.assessment_id IS NOT NULL';
        }

        if ($categoryId !== null && $categoryId !== '') {
            $where .= ' AND a.category_id = :category';
            $params['category'] = $categoryId;
        }

        if ($kind !== null && $kind !== '') {
            $where .= ' AND a.kind = :kind';
            $params['kind'] = $kind;
        }

        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (a.title ILIKE :search OR a.description ILIKE :search)';
            $params['search'] = '%' . trim($search) . '%';
        }

        $params['limit'] = max(1, min(500, $limit));

        $rows = $this->connection->fetchAllAssociative(
            "WITH me AS (
                SELECT department_id, team_id, branch_id FROM users WHERE id = :user AND tenant_id = :tenant
            ),
            matched AS (
                SELECT aa.assessment_id, MIN(aa.due_date) AS due_date
                FROM assessment_assignments aa, me
                WHERE aa.tenant_id = :tenant AND (
                    aa.assignment_type = 'all'
                    OR (aa.assignment_type = 'user' AND aa.target_id = :user)
                    OR (aa.assignment_type = 'department' AND aa.target_id = me.department_id)
                    OR (aa.assignment_type = 'team' AND aa.target_id = me.team_id)
                    OR (aa.assignment_type = 'branch' AND aa.target_id = me.branch_id)
                )
                GROUP BY aa.assessment_id
            ),
            my_attempts AS (
                SELECT assessment_id,
                       COUNT(*) FILTER (WHERE status IN ('submitted','auto_submitted','grading','graded')) AS attempt_count,
                       MAX(percentage) FILTER (WHERE status IN ('graded','auto_submitted')) AS best_score,
                       bool_or(status IN ('in_progress','not_started')) AS has_open,
                       bool_or(status IN ('submitted','grading')) AS has_submitted,
                       bool_or(status = 'graded' AND passed) AS has_passed,
                       bool_or(status = 'graded' AND NOT passed) AS has_failed
                FROM attempts
                WHERE tenant_id = :tenant AND user_id = :user
                GROUP BY assessment_id
            )
            SELECT a.id, a.title, a.description, a.kind, a.category_id, a.course_id,
                   a.time_limit_minutes, a.max_attempts, a.passing_score, a.passing_type,
                   a.proctoring_level, a.difficulty, a.available_from, a.available_until,
                   cat.name AS category_name, c.title AS course_title,
                   {$this->derivedForCatalogue()},
                   (m.assessment_id IS NOT NULL) AS is_mandatory,
                   m.due_date AS due_date,
                   COALESCE(ma.attempt_count, 0) AS attempt_count,
                   ma.best_score AS best_score,
                   COALESCE(ma.has_open, false) AS has_open,
                   COALESCE(ma.has_submitted, false) AS has_submitted,
                   COALESCE(ma.has_passed, false) AS has_passed,
                   COALESCE(ma.has_failed, false) AS has_failed
            FROM assessments a
            LEFT JOIN lookups cat ON cat.id = a.category_id AND cat.kind = 'category'
            LEFT JOIN courses c ON c.id = a.course_id AND c.tenant_id = a.tenant_id
            LEFT JOIN matched m ON m.assessment_id = a.id
            LEFT JOIN my_attempts ma ON ma.assessment_id = a.id
            WHERE {$where}
            ORDER BY (m.due_date IS NULL), m.due_date, a.title
            LIMIT :limit",
            $params,
            $types,
        );

        return array_map(fn (array $r): LearnerAssessment => $this->hydrateLearnerAssessment($r), $rows);
    }

    private function derivedForCatalogue(): string
    {
        return '(SELECT COUNT(*) FROM assessment_questions aq WHERE aq.assessment_id = a.id) AS question_count,
                COALESCE((
                    SELECT SUM(COALESCE(aq.points_override, q.points))
                    FROM assessment_questions aq JOIN questions q ON q.id = aq.question_id
                    WHERE aq.assessment_id = a.id
                ), 0) AS total_points';
    }

    /**
     * @param array<string, mixed> $r
     */
    private function hydrateLearnerAssessment(array $r): LearnerAssessment
    {
        $isMandatory = (bool) $r['is_mandatory'];
        $status = LearnerAssessment::statusFrom(
            (bool) $r['has_open'],
            (bool) $r['has_submitted'],
            (bool) $r['has_passed'],
            (bool) $r['has_failed'],
            $isMandatory,
        );

        return new LearnerAssessment(
            assessmentId: (string) $r['id'],
            title: (string) $r['title'],
            description: $r['description'] !== null ? (string) $r['description'] : null,
            kind: (string) $r['kind'],
            categoryId: $r['category_id'] !== null ? (string) $r['category_id'] : null,
            categoryName: $r['category_name'] !== null ? (string) $r['category_name'] : null,
            courseId: $r['course_id'] !== null ? (string) $r['course_id'] : null,
            courseTitle: $r['course_title'] !== null ? (string) $r['course_title'] : null,
            questionCount: (int) $r['question_count'],
            totalPoints: (float) $r['total_points'],
            timeLimitMinutes: $r['time_limit_minutes'] !== null ? (int) $r['time_limit_minutes'] : null,
            maxAttempts: $r['max_attempts'] !== null ? (int) $r['max_attempts'] : null,
            passingScore: (float) $r['passing_score'],
            passingType: (string) $r['passing_type'],
            proctoringLevel: (string) $r['proctoring_level'],
            difficulty: $r['difficulty'] !== null ? (string) $r['difficulty'] : null,
            availableFrom: $this->date($r['available_from'] ?? null),
            availableUntil: $this->date($r['available_until'] ?? null),
            enrollmentStatus: $status,
            attemptCount: (int) $r['attempt_count'],
            bestScore: $r['best_score'] !== null ? (float) $r['best_score'] : null,
            isMandatory: $isMandatory,
            dueDate: $this->date($r['due_date'] ?? null),
        );
    }

    /**
     * Copies an assessment into a fresh draft — configuration, questions,
     * sections and random rules — so a new one can start from a working one
     * rather than from nothing. The copy carries no attempts and no publish
     * date; it is a draft the moment it exists.
     */
    public function duplicate(string $tenantId, string $id): ?Assessment
    {
        if ($this->find($tenantId, $id) === null) {
            return null;
        }

        $newId = $this->connection->transactional(function (Connection $tx) use ($tenantId, $id): string {
            $newId = Uuid::uuid4()->toString();

            // The row itself: every column copied, but reset to an unpublished
            // draft with a new title. Listing the columns keeps the copy honest
            // if one is added — a missing column is a NOT NULL failure, not a
            // silent loss.
            $tx->executeStatement(
                "INSERT INTO assessments
                     (id, tenant_id, title, description, instructions, kind, status, difficulty,
                      course_id, module_id, category_id, time_limit_minutes, available_from, available_until,
                      grace_period_minutes, max_attempts, attempts_gap_hours, passing_score, passing_type,
                      question_selection, random_question_count, shuffle_questions, shuffle_options,
                      reveal_answers, show_score_immediately, show_explanations, allow_backward_navigation,
                      requires_manual_grading, proctoring_level, training_id, welcome_message, completion_message,
                      negative_marking, negative_marking_percentage, partial_credit, questions_per_page,
                      show_question_numbers, show_progress_bar, grading_scale_id, created_by, created_at, updated_at)
                 SELECT :newId, tenant_id, title || ' (Copy)', description, instructions, kind, 'draft', difficulty,
                        course_id, module_id, category_id, time_limit_minutes, available_from, available_until,
                        grace_period_minutes, max_attempts, attempts_gap_hours, passing_score, passing_type,
                        question_selection, random_question_count, shuffle_questions, shuffle_options,
                        reveal_answers, show_score_immediately, show_explanations, allow_backward_navigation,
                        requires_manual_grading, proctoring_level, training_id, welcome_message, completion_message,
                        negative_marking, negative_marking_percentage, partial_credit, questions_per_page,
                        show_question_numbers, show_progress_bar, grading_scale_id, created_by, NOW(), NOW()
                 FROM assessments WHERE id = :id AND tenant_id = :tenant",
                ['newId' => $newId, 'id' => $id, 'tenant' => $tenantId],
            );

            // Sections keep their own new ids, mapped old→new so the questions
            // and rules that point at them follow the copy, not the original.
            $sectionMap = [];
            $sections = $tx->fetchAllAssociative(
                'SELECT id FROM assessment_sections WHERE assessment_id = :id',
                ['id' => $id],
            );
            foreach ($sections as $section) {
                $oldSectionId = (string) $section['id'];
                $newSectionId = Uuid::uuid4()->toString();
                $sectionMap[$oldSectionId] = $newSectionId;

                $tx->executeStatement(
                    'INSERT INTO assessment_sections
                         (id, tenant_id, assessment_id, title, description, instructions,
                          time_limit_minutes, selection_type, random_count, sort_order, created_at, updated_at)
                     SELECT :newSectionId, tenant_id, :newId, title, description, instructions,
                            time_limit_minutes, selection_type, random_count, sort_order, NOW(), NOW()
                     FROM assessment_sections WHERE id = :oldSectionId',
                    ['newSectionId' => $newSectionId, 'newId' => $newId, 'oldSectionId' => $oldSectionId],
                );
            }

            $this->copyQuestionsAndRules($tx, $id, $newId, $sectionMap);

            return $newId;
        });

        return $this->find($tenantId, $newId);
    }

    /**
     * @param array<string, string> $sectionMap old section id → new section id
     */
    private function copyQuestionsAndRules(Connection $tx, string $id, string $newId, array $sectionMap): void
    {
        $questions = $tx->fetchAllAssociative(
            'SELECT question_id, section_id, points_override, sort_order, is_required
             FROM assessment_questions WHERE assessment_id = :id',
            ['id' => $id],
        );
        foreach ($questions as $q) {
            $section = $q['section_id'] === null ? null : ($sectionMap[(string) $q['section_id']] ?? null);
            $tx->executeStatement(
                'INSERT INTO assessment_questions
                     (id, tenant_id, assessment_id, question_id, section_id, points_override, sort_order, is_required)
                 SELECT gen_random_uuid(), tenant_id, :newId, question_id, :section, points_override, sort_order, is_required
                 FROM assessment_questions
                 WHERE assessment_id = :id AND question_id = :questionId',
                ['newId' => $newId, 'section' => $section, 'id' => $id, 'questionId' => (string) $q['question_id']],
            );
        }

        $rules = $tx->fetchAllAssociative(
            'SELECT id, section_id FROM assessment_random_rules WHERE assessment_id = :id',
            ['id' => $id],
        );
        foreach ($rules as $rule) {
            $section = $rule['section_id'] === null ? null : ($sectionMap[(string) $rule['section_id']] ?? null);
            $tx->executeStatement(
                'INSERT INTO assessment_random_rules
                     (id, tenant_id, assessment_id, section_id, bank_id, category_id, category_kind,
                      difficulty, question_types, question_count, points_per_question, sort_order, created_at, updated_at)
                 SELECT gen_random_uuid(), tenant_id, :newId, :section, bank_id, category_id, category_kind,
                        difficulty, question_types, question_count, points_per_question, sort_order, NOW(), NOW()
                 FROM assessment_random_rules WHERE id = :ruleId',
                ['newId' => $newId, 'section' => $section, 'ruleId' => (string) $rule['id']],
            );
        }
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
        ?string $sectionId = null,
    ): bool {
        $affected = $this->connection->executeStatement(
            'INSERT INTO assessment_questions
                 (tenant_id, assessment_id, question_id, points_override, sort_order, is_required, section_id)
             VALUES (
                 :tenant, :assessment, :question, :points,
                 COALESCE(:sortOrder, (
                     SELECT COALESCE(MAX(sort_order), -1) + 1
                     FROM assessment_questions
                     WHERE assessment_id = :assessment
                 )),
                 :required, :section
             )
             ON CONFLICT (assessment_id, question_id) DO NOTHING',
            [
                'tenant'     => $tenantId,
                'assessment' => $assessmentId,
                'question'   => $questionId,
                'points'     => $pointsOverride,
                'sortOrder'  => $sortOrder,
                'required'   => $isRequired ? 'true' : 'false',
                'section'    => $sectionId,
            ],
        );

        if ($affected > 0) {
            $this->refreshManualGradingFlag($tenantId, $assessmentId);
        }

        return $affected > 0;
    }

    public function setQuestionPoints(
        string $tenantId,
        string $assessmentId,
        string $questionId,
        ?float $pointsOverride,
    ): bool {
        // A null override falls back to the question's own points — the same
        // meaning it has on attach, so clearing it here restores the default.
        return $this->connection->executeStatement(
            'UPDATE assessment_questions SET points_override = :points
             WHERE tenant_id = :tenant AND assessment_id = :assessment AND question_id = :question',
            [
                'points'     => $pointsOverride,
                'tenant'     => $tenantId,
                'assessment' => $assessmentId,
                'question'   => $questionId,
            ],
        ) > 0;
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
            'proctoring'       => $a->proctoringLevel->value,
            'training'         => $a->trainingId,
            'welcome'          => $a->welcomeMessage,
            'completion'       => $a->completionMessage,
            'negMarking'       => $a->negativeMarking ? 'true' : 'false',
            'negMarkingPct'    => $a->negativeMarkingPercentage,
            'partialCredit'    => $a->partialCredit ? 'true' : 'false',
            'perPage'          => $a->questionsPerPage,
            'showNumbers'      => $a->showQuestionNumbers ? 'true' : 'false',
            'showProgress'     => $a->showProgressBar ? 'true' : 'false',
            'gradingScale'     => $a->gradingScaleId,
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
            trainingId: $this->text($row['training_id'] ?? null),
            welcomeMessage: $this->text($row['welcome_message'] ?? null),
            completionMessage: $this->text($row['completion_message'] ?? null),
            negativeMarking: (bool) ($row['negative_marking'] ?? false),
            negativeMarkingPercentage: (float) ($row['negative_marking_percentage'] ?? 0),
            partialCredit: (bool) ($row['partial_credit'] ?? true),
            questionsPerPage: (int) ($row['questions_per_page'] ?? 1),
            showQuestionNumbers: (bool) ($row['show_question_numbers'] ?? true),
            showProgressBar: (bool) ($row['show_progress_bar'] ?? true),
            gradingScaleId: $this->text($row['grading_scale_id'] ?? null),
            createdBy: $this->text($row['created_by'] ?? null),
            publishedAt: $this->date($row['published_at'] ?? null),
            createdAt: $this->date($row['created_at'] ?? null),
            updatedAt: $this->date($row['updated_at'] ?? null),
            questionCount: (int) ($row['question_count'] ?? 0),
            totalPoints: (float) ($row['total_points'] ?? 0),
            // Present only on the listing, which joins the attempts.
            totalAttempts: (int) ($row['total_attempts'] ?? 0),
            totalCompletions: (int) ($row['total_completions'] ?? 0),
            passRate: (float) ($row['pass_rate'] ?? 0),
            avgScore: (float) ($row['avg_score'] ?? 0),
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
