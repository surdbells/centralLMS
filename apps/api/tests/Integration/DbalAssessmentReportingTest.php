<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Assessment\QuestionBank;
use Central\Api\Domain\Assessment\QuestionOption;
use Central\Api\Domain\Assessment\QuestionType;
use Central\Api\Domain\Shared\Difficulty;
use Central\Api\Infrastructure\Persistence\DbalAssessmentRepository;
use Central\Api\Infrastructure\Persistence\DbalQuestionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * The reporting projections — learner report, item analysis, admin dashboard
 * and the learner catalogue — against a real PostgreSQL with seeded attempts.
 */
#[CoversClass(DbalAssessmentRepository::class)]
final class DbalAssessmentReportingTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalAssessmentRepository $assessments;
    private DbalQuestionRepository $questions;

    private string $tenant;
    private string $learner1;
    private string $learner2;
    private string $department;
    private string $category;
    private string $assessmentId;
    private Question $q1;
    private Question $q2;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable.');
        }

        $this->questions = new DbalQuestionRepository(self::$connection);
        $this->assessments = new DbalAssessmentRepository(self::$connection, $this->questions);

        $this->tenant = $this->createTenant();
        $this->department = $this->createLookup('department', 'Engineering');
        $this->category = $this->createLookup('category', 'Compliance');
        $this->learner1 = $this->createUser('Ada', 'Lovelace', $this->department);
        $this->learner2 = $this->createUser('Alan', 'Turing', null);

        // A published assessment in the category, with two questions of
        // different difficulty attached.
        $this->assessmentId = $this->createAssessment('Safety Exam', $this->category);

        $bank = $this->questions->createBank(
            new QuestionBank(id: '', tenantId: $this->tenant, name: 'Bank ' . substr(Uuid::uuid4()->toString(), 0, 8)),
        );
        $this->q1 = $this->makeQuestion($bank->id, 'Q1?', Difficulty::Beginner);
        $this->q2 = $this->makeQuestion($bank->id, 'Q2?', Difficulty::Professional);
        $this->attach($this->q1->id, 0);
        $this->attach($this->q2->id, 1);

        // learner1 passed (80%); learner2 failed (40%). Both answered Q1
        // correctly; only learner1 got Q2.
        $a1 = $this->seedGradedAttempt($this->learner1, 80.0, true, 8.0);
        $this->seedAnswer($a1, $this->learner1, $this->q1, true);
        $this->seedAnswer($a1, $this->learner1, $this->q2, true);

        $a2 = $this->seedGradedAttempt($this->learner2, 40.0, false, 4.0);
        $this->seedAnswer($a2, $this->learner2, $this->q1, true);
        $this->seedAnswer($a2, $this->learner2, $this->q2, false);

        // learner1 is assigned this assessment and holds an active certificate.
        $this->assign('user', $this->learner1);
        $this->issueCertificate($this->learner1);
    }

    protected function tearDown(): void
    {
        if (self::$connection === null) {
            return;
        }

        // Not everything cascades from tenants, so tenant-scoped rows are
        // cleared child-first before the tenant itself.
        $tables = [
            'attempt_answers', 'attempt_questions', 'attempts', 'certificates',
            'assessment_assignments', 'assessment_questions', 'question_tags', 'question_options',
            'questions', 'question_banks', 'assessments', 'users', 'lookups',
        ];
        foreach ($tables as $table) {
            self::$connection->executeStatement("DELETE FROM {$table} WHERE tenant_id = :id", ['id' => $this->tenant]);
        }
        self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $this->tenant]);
    }

    #[Test]
    public function learner_report_aggregates_each_learners_attempts(): void
    {
        $rows = $this->assessments->learnerReport($this->tenant, $this->assessmentId, null, null, null);

        self::assertCount(2, $rows);
        $byName = [];
        foreach ($rows as $r) {
            $byName[$r['learner_name']] = $r;
        }

        self::assertSame('passed', $byName['Ada Lovelace']['status']);
        self::assertTrue($byName['Ada Lovelace']['passed']);
        self::assertSame(80.0, $byName['Ada Lovelace']['best_score']);
        self::assertSame('Engineering', $byName['Ada Lovelace']['department']);
        self::assertTrue($byName['Ada Lovelace']['certificate_issued']);

        self::assertSame('failed', $byName['Alan Turing']['status']);
        self::assertFalse($byName['Alan Turing']['passed']);
        self::assertNull($byName['Alan Turing']['department']);
        self::assertFalse($byName['Alan Turing']['certificate_issued']);
    }

    #[Test]
    public function learner_report_status_filter_narrows_to_matching_learners(): void
    {
        $passed = $this->assessments->learnerReport($this->tenant, $this->assessmentId, null, 'passed', null);
        self::assertCount(1, $passed);
        self::assertSame('Ada Lovelace', $passed[0]['learner_name']);

        $failed = $this->assessments->learnerReport($this->tenant, $this->assessmentId, null, 'failed', null);
        self::assertCount(1, $failed);
        self::assertSame('Alan Turing', $failed[0]['learner_name']);
    }

    #[Test]
    public function item_analysis_reports_difficulty_and_discrimination(): void
    {
        $rows = $this->assessments->itemAnalysis($this->tenant, $this->assessmentId);

        self::assertCount(2, $rows);
        $byText = [];
        foreach ($rows as $r) {
            $byText[$r['question_text']] = $r;
        }

        // Q1: both correct -> p-value 1.0; a constant item has no discrimination.
        self::assertSame(2, $byText['Q1?']['total_attempts']);
        self::assertSame(2, $byText['Q1?']['correct_count']);
        self::assertSame(1.0, $byText['Q1?']['difficulty_index']);
        self::assertSame(0.0, $byText['Q1?']['discrimination_index']);

        // Q2: only the high scorer got it -> p-value 0.5, perfect discrimination.
        self::assertSame(1, $byText['Q2?']['correct_count']);
        self::assertSame(0.5, $byText['Q2?']['difficulty_index']);
        self::assertSame(1.0, $byText['Q2?']['discrimination_index']);
    }

    #[Test]
    public function dashboard_summarises_the_tenant(): void
    {
        $now = new \DateTimeImmutable('now');
        $from = $now->modify('-30 days');

        $dash = $this->assessments->dashboard($this->tenant, $now, $from);

        self::assertSame(2, $dash['stats']['totalLearners']);
        self::assertSame(2, $dash['stats']['totalQuestions']);
        self::assertCount(6, $dash['monthlyTrends']); // trailing six months, gaps filled

        $diffByLevel = [];
        foreach ($dash['difficultyBreakdown'] as $d) {
            $diffByLevel[$d['difficulty']] = $d;
        }
        self::assertSame(1, $diffByLevel['beginner']['count']);
        self::assertSame(1, $diffByLevel['professional']['count']);

        // The activity feed carries the two submissions and the two gradings.
        self::assertNotEmpty($dash['recentActivity']);
    }

    #[Test]
    public function catalogue_shows_the_assignment_and_the_learners_own_result(): void
    {
        $rows = $this->assessments->catalogueForLearner($this->tenant, $this->learner1);

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($this->assessmentId, $row->assessmentId);
        self::assertSame('Compliance', $row->categoryName);
        self::assertSame(2, $row->questionCount);
        self::assertTrue($row->isMandatory);       // assigned to this learner
        self::assertSame('passed', $row->enrollmentStatus);
        self::assertSame(1, $row->attemptCount);
        self::assertSame(80.0, $row->bestScore);
        self::assertSame([], $row->prerequisites);
    }

    #[Test]
    public function catalogue_counts_an_auto_submitted_pass_as_passed(): void
    {
        // A fresh learner whose only attempt is an auto-submitted (timer-expiry)
        // pass — the terminal status is 'auto_submitted', not 'graded'.
        $learner = $this->createUser('Grace', 'Hopper', null);
        $attempt = (string) $this->db()->fetchOne(
            "INSERT INTO attempts
                (tenant_id, assessment_id, user_id, attempt_number, status,
                 raw_score, max_score, percentage, passed, time_spent_seconds,
                 started_at, submitted_at, graded_at)
             VALUES (:t, :a, :u, 1, 'auto_submitted', 9, 10, 90, true, 300,
                 now() - interval '2 days', now() - interval '1 day', now() - interval '1 day')
             RETURNING id",
            ['t' => $this->tenant, 'a' => $this->assessmentId, 'u' => $learner],
        );
        self::assertNotSame('', $attempt);

        $rows = $this->assessments->catalogueForLearner($this->tenant, $learner);
        self::assertCount(1, $rows);
        self::assertSame('passed', $rows[0]->enrollmentStatus);
        self::assertSame(1, $rows[0]->attemptCount);
        self::assertSame(90.0, $rows[0]->bestScore);
    }

    #[Test]
    public function catalogue_assigned_only_hides_unassigned_assessments(): void
    {
        // learner2 has no assignment, so assigned-only is empty for them...
        self::assertCount(0, $this->assessments->catalogueForLearner($this->tenant, $this->learner2, assignedOnly: true));
        // ...but the published assessment still shows in their full catalogue.
        $all = $this->assessments->catalogueForLearner($this->tenant, $this->learner2);
        self::assertCount(1, $all);
        self::assertFalse($all[0]->isMandatory);
        self::assertSame('failed', $all[0]->enrollmentStatus);
    }

    // --- fixtures ---------------------------------------------------------

    private function makeQuestion(string $bankId, string $text, Difficulty $difficulty): Question
    {
        return $this->questions->createQuestion(new Question(
            id: '',
            tenantId: $this->tenant,
            bankId: $bankId,
            type: QuestionType::MultipleChoiceSingle,
            text: $text,
            difficulty: $difficulty,
            options: [
                new QuestionOption(id: '', tenantId: $this->tenant, questionId: '', text: 'a', isCorrect: true),
                new QuestionOption(id: '', tenantId: $this->tenant, questionId: '', text: 'b', isCorrect: false),
            ],
        ));
    }

    private function attach(string $questionId, int $sort): void
    {
        $this->assessments->attachQuestion($this->tenant, $this->assessmentId, $questionId, 5.0, $sort, true);
    }

    private function seedGradedAttempt(string $userId, float $percentage, bool $passed, float $raw): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO attempts
                (tenant_id, assessment_id, user_id, attempt_number, status,
                 raw_score, max_score, percentage, passed, time_spent_seconds,
                 started_at, submitted_at, graded_at)
             VALUES (:tenant, :assessment, :user, 1, 'graded',
                 :raw, 10, :pct, :passed, 300,
                 now() - interval '2 days', now() - interval '1 day', now() - interval '1 day')
             RETURNING id",
            [
                'tenant' => $this->tenant, 'assessment' => $this->assessmentId, 'user' => $userId,
                'raw' => $raw, 'pct' => $percentage, 'passed' => $passed ? 'true' : 'false',
            ],
        );
    }

    private function seedAnswer(string $attemptId, string $userId, Question $q, bool $correct): void
    {
        // The dealt paper...
        $this->db()->executeStatement(
            'INSERT INTO attempt_questions (tenant_id, attempt_id, question_id, points_possible, sort_order)
             VALUES (:tenant, :attempt, :question, 5, 0)',
            ['tenant' => $this->tenant, 'attempt' => $attemptId, 'question' => $q->id],
        );
        // ...and the graded answer.
        $this->db()->executeStatement(
            "INSERT INTO attempt_answers
                (tenant_id, attempt_id, user_id, question_id, answer, is_correct,
                 points_earned, points_possible, time_spent_seconds, graded_at)
             VALUES (:tenant, :attempt, :user, :question, '{\"optionIds\":[\"x\"]}'::jsonb, :correct,
                 :earned, 5, 30, now() - interval '1 day')",
            [
                'tenant' => $this->tenant, 'attempt' => $attemptId, 'user' => $userId, 'question' => $q->id,
                'correct' => $correct ? 'true' : 'false', 'earned' => $correct ? 5 : 0,
            ],
        );
    }

    private function assign(string $type, string $targetId): void
    {
        $this->db()->executeStatement(
            'INSERT INTO assessment_assignments (tenant_id, assessment_id, assignment_type, target_id, due_date)
             VALUES (:tenant, :assessment, :type, :target, now() + interval \'7 days\')',
            ['tenant' => $this->tenant, 'assessment' => $this->assessmentId, 'type' => $type, 'target' => $targetId],
        );
    }

    private function issueCertificate(string $userId): void
    {
        $this->db()->executeStatement(
            "INSERT INTO certificates
                (tenant_id, user_id, assessment_id, source, status, number,
                 learner_name, title, verification_code, verification_hash)
             VALUES (:tenant, :user, :assessment, 'assessment', 'active', :number,
                 'Ada Lovelace', 'Safety Exam', :code, :hash)",
            [
                'tenant' => $this->tenant, 'user' => $userId, 'assessment' => $this->assessmentId,
                'number' => 'CERT-' . substr(Uuid::uuid4()->toString(), 0, 8),
                'code' => substr(Uuid::uuid4()->toString(), 0, 12), 'hash' => Uuid::uuid4()->toString(),
            ],
        );
    }

    private function createTenant(): string
    {
        $id = Uuid::uuid4()->toString();
        $this->db()->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, 'active')",
            ['id' => $id, 'name' => 'report test', 'code' => 'rpt-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createLookup(string $kind, string $name): string
    {
        return (string) $this->db()->fetchOne(
            'INSERT INTO lookups (tenant_id, kind, code, name) VALUES (:tenant, :kind, :code, :name) RETURNING id',
            ['tenant' => $this->tenant, 'kind' => $kind, 'code' => strtolower($name), 'name' => $name],
        );
    }

    private function createUser(string $first, string $last, ?string $departmentId): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO users (tenant_id, email, first_name, last_name, status, department_id)
             VALUES (:tenant, :email, :first, :last, 'active', :dept) RETURNING id",
            [
                'tenant' => $this->tenant,
                'email'  => strtolower($first) . '-' . Uuid::uuid4()->toString() . '@rpt.test',
                'first'  => $first, 'last' => $last, 'dept' => $departmentId,
            ],
        );
    }

    private function createAssessment(string $title, string $categoryId): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO assessments (tenant_id, title, status, category_id, published_at)
             VALUES (:tenant, :title, 'published', :category, now() - interval '3 days') RETURNING id",
            ['tenant' => $this->tenant, 'title' => $title, 'category' => $categoryId],
        );
    }

    private function db(): Connection
    {
        return self::$connection ?? self::fail('PostgreSQL is not reachable.');
    }

    private static function connect(): ?Connection
    {
        $root = dirname(__DIR__, 2);

        if (is_file($root . '/.env')) {
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }

        try {
            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port'     => (int) ($_ENV['DB_PORT'] ?? 5432),
                'dbname'   => $_ENV['DB_NAME'] ?? 'central_lms',
                'user'     => $_ENV['DB_USER'] ?? 'central_lms',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
            ], new \Doctrine\DBAL\Configuration());

            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }
}
