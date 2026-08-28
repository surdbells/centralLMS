<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Assessment\Rubric;
use Central\Api\Domain\Assessment\RubricCriterion;
use Central\Api\Domain\Assessment\RubricKind;
use Central\Api\Domain\Assessment\RubricLevel;
use Central\Api\Infrastructure\Persistence\DbalGradingAssignmentRepository;
use Central\Api\Infrastructure\Persistence\DbalRubricRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Rubrics and grading assignments against a real PostgreSQL.
 *
 * The things worth proving here are the ones a unit test cannot: that a
 * rubric's total comes from its bands rather than a stored number, that
 * replacing its criteria does not leave the old ones behind, and that an
 * attempt cannot end up assigned to two markers at once.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalRubricRepository::class)]
#[CoversClass(DbalGradingAssignmentRepository::class)]
final class DbalRubricRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalRubricRepository $rubrics;
    private DbalGradingAssignmentRepository $assignments;

    private string $tenantA;
    private string $tenantB;
    private string $marker;
    private string $otherMarker;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->rubrics = new DbalRubricRepository(self::$connection);
        $this->assignments = new DbalGradingAssignmentRepository(self::$connection);

        $this->tenantA = $this->createTenant('rub-a');
        $this->tenantB = $this->createTenant('rub-b');
        $this->marker = $this->createUser($this->tenantA, 'marker');
        $this->otherMarker = $this->createUser($this->tenantA, 'second');
    }

    protected function tearDown(): void
    {
        if (self::$connection === null) {
            return;
        }

        foreach ([$this->tenantA ?? null, $this->tenantB ?? null] as $tenantId) {
            if ($tenantId === null) {
                continue;
            }

            self::$connection->executeStatement('DELETE FROM users WHERE tenant_id = :id', ['id' => $tenantId]);
            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    // --- rubrics ------------------------------------------------------------

    #[Test]
    public function a_rubric_round_trips_with_its_criteria_and_bands(): void
    {
        $saved = $this->rubrics->save($this->essayRubric());

        $read = $this->rubrics->find($this->tenantA, $saved->id);

        self::assertNotNull($read);
        self::assertSame('Essay', $read->name);
        self::assertCount(2, $read->criteria);
        self::assertSame('Evidence', $read->criteria[0]->title);
        self::assertCount(3, $read->criteria[0]->levels);
        self::assertSame('Excellent', $read->criteria[0]->levels[0]->label);
    }

    #[Test]
    public function the_total_comes_from_the_bands_not_a_stored_number(): void
    {
        // `maxPoints` is deliberately understated: the bands say 4 and 3, so
        // the rubric is worth 7 however the column was filled in.
        $rubric = $this->rubrics->save($this->essayRubric(statedMaxPoints: 1.0));

        self::assertSame(7.0, $rubric->totalPoints());
        self::assertSame(4.0, $rubric->criteria[0]->effectiveMaxPoints());
    }

    #[Test]
    public function replacing_criteria_removes_the_old_ones_rather_than_stacking_them(): void
    {
        $saved = $this->rubrics->save($this->essayRubric());

        $replaced = $this->rubrics->replaceCriteria($this->tenantA, $saved->id, [
            new RubricCriterion(
                id: '',
                tenantId: $this->tenantA,
                rubricId: $saved->id,
                title: 'Accuracy',
                description: null,
                maxPoints: 5.0,
                levels: [
                    new RubricLevel('', $this->tenantA, '', 'Right', null, 5.0),
                    new RubricLevel('', $this->tenantA, '', 'Wrong', null, 0.0),
                ],
            ),
        ]);

        self::assertCount(1, $replaced->criteria);
        self::assertSame('Accuracy', $replaced->criteria[0]->title);
        self::assertSame(5.0, $replaced->totalPoints());

        // The bands of the criteria that went should have gone with them.
        $orphans = (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM rubric_levels l
             LEFT JOIN rubric_criteria c ON c.id = l.criterion_id
             WHERE l.tenant_id = :tenant AND c.id IS NULL',
            ['tenant' => $this->tenantA],
        );

        self::assertSame(0, $orphans);
    }

    #[Test]
    public function a_rubric_with_a_criterion_that_has_no_bands_is_not_usable(): void
    {
        $rubric = $this->rubrics->save(new Rubric(
            id: Uuid::uuid4()->toString(),
            tenantId: $this->tenantA,
            name: 'Half written',
            description: null,
            criteria: [
                new RubricCriterion('', $this->tenantA, '', 'Evidence', null, 4.0, levels: []),
            ],
        ));

        // A marker opening this has nothing to choose between, and saying so
        // beats their discovering it mid-marking.
        self::assertFalse($rubric->isUsable());
    }

    #[Test]
    public function duplicating_carries_the_criteria_and_bands_across(): void
    {
        $original = $this->rubrics->save($this->essayRubric());

        $copy = $this->rubrics->duplicate($this->tenantA, $original->id, 'Essay (copy)', $this->marker);

        self::assertNotNull($copy);
        self::assertNotSame($original->id, $copy->id);
        self::assertSame('Essay (copy)', $copy->name);
        self::assertCount(2, $copy->criteria);
        self::assertSame(7.0, $copy->totalPoints());

        // Its own rows, not the original's — editing the copy must not touch
        // what the original is marked against.
        self::assertNotSame($original->criteria[0]->id, $copy->criteria[0]->id);
    }

    #[Test]
    public function one_tenant_cannot_read_anothers_rubric(): void
    {
        $mine = $this->rubrics->save($this->essayRubric());

        self::assertNull($this->rubrics->find($this->tenantB, $mine->id));
        self::assertSame([], $this->rubrics->all($this->tenantB));
    }

    #[Test]
    public function deleting_is_soft_so_marks_already_given_keep_their_meaning(): void
    {
        $rubric = $this->rubrics->save($this->essayRubric());

        self::assertTrue($this->rubrics->delete($this->tenantA, $rubric->id));
        self::assertNull($this->rubrics->find($this->tenantA, $rubric->id));

        $stillThere = (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM rubrics WHERE id = :id',
            ['id' => $rubric->id],
        );

        self::assertSame(1, $stillThere);
    }

    #[Test]
    public function two_rubrics_in_one_tenant_may_not_share_a_name(): void
    {
        $this->rubrics->save($this->essayRubric());

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        // A marker choosing between two identically named rubrics is choosing
        // at random.
        $this->rubrics->save($this->essayRubric());
    }

    // --- grading assignments -------------------------------------------------

    #[Test]
    public function assigning_twice_replaces_rather_than_stacking(): void
    {
        $attemptId = $this->createAttempt();

        $this->assignments->assign($this->tenantA, $attemptId, $this->marker, $this->marker);
        $second = $this->assignments->assign($this->tenantA, $attemptId, $this->otherMarker, $this->marker);

        self::assertSame($this->otherMarker, $second->assignedTo);

        $rows = (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM grading_assignments WHERE attempt_id = :id',
            ['id' => $attemptId],
        );

        // One row, so there is never a question of which assignment is current.
        self::assertSame(1, $rows);
    }

    #[Test]
    public function reassigning_clears_a_previous_skip(): void
    {
        $attemptId = $this->createAttempt();

        $this->assignments->assign($this->tenantA, $attemptId, $this->marker, $this->marker);
        $this->assignments->skip($this->tenantA, $attemptId, 'I taught this cohort.');

        $handedOn = $this->assignments->assign(
            $this->tenantA,
            $attemptId,
            $this->otherMarker,
            $this->marker,
        );

        // The new marker has not set it aside, and leaving the flag would hide
        // it from the person who now owns it.
        self::assertNull($handedOn->skippedAt);
        self::assertNull($handedOn->skipReason);
        self::assertTrue($handedOn->isOpen());
    }

    #[Test]
    public function a_skipped_assignment_keeps_its_reason_and_stops_being_open(): void
    {
        $attemptId = $this->createAttempt();

        $this->assignments->assign($this->tenantA, $attemptId, $this->marker, $this->marker);
        $skipped = $this->assignments->skip($this->tenantA, $attemptId, 'Conflict of interest.');

        self::assertNotNull($skipped);
        self::assertFalse($skipped->isOpen());
        self::assertSame('Conflict of interest.', $skipped->skipReason);
    }

    #[Test]
    public function releasing_removes_the_row_so_it_returns_to_the_general_queue(): void
    {
        $attemptId = $this->createAttempt();

        $this->assignments->assign($this->tenantA, $attemptId, $this->marker, $this->marker);

        self::assertTrue($this->assignments->release($this->tenantA, $attemptId));
        self::assertNull($this->assignments->find($this->tenantA, $attemptId));
    }

    #[Test]
    public function a_batch_is_shared_out_round_robin(): void
    {
        $attempts = [$this->createAttempt(), $this->createAttempt(), $this->createAttempt()];

        $assigned = $this->assignments->assignEvenly(
            $this->tenantA,
            $attempts,
            [$this->marker, $this->otherMarker],
            $this->marker,
        );

        self::assertSame(3, $assigned);

        $found = $this->assignments->forAttempts($this->tenantA, $attempts);

        self::assertSame($this->marker, $found[$attempts[0]]->assignedTo);
        self::assertSame($this->otherMarker, $found[$attempts[1]]->assignedTo);
        self::assertSame($this->marker, $found[$attempts[2]]->assignedTo);
    }

    #[Test]
    public function one_tenant_cannot_see_anothers_assignments(): void
    {
        $attemptId = $this->createAttempt();

        $this->assignments->assign($this->tenantA, $attemptId, $this->marker, $this->marker);

        self::assertNull($this->assignments->find($this->tenantB, $attemptId));
        self::assertSame([], $this->assignments->forAttempts($this->tenantB, [$attemptId]));
    }

    // --- fixtures -------------------------------------------------------------

    private function essayRubric(float $statedMaxPoints = 4.0): Rubric
    {
        return new Rubric(
            id: Uuid::uuid4()->toString(),
            tenantId: $this->tenantA,
            name: 'Essay',
            description: 'For written answers.',
            kind: RubricKind::Analytic,
            createdBy: $this->marker,
            criteria: [
                new RubricCriterion(
                    id: '',
                    tenantId: $this->tenantA,
                    rubricId: '',
                    title: 'Evidence',
                    description: 'Cites procedure.',
                    maxPoints: $statedMaxPoints,
                    levels: [
                        new RubricLevel('', $this->tenantA, '', 'Excellent', 'Three or more sources.', 4.0),
                        new RubricLevel('', $this->tenantA, '', 'Adequate', null, 2.0),
                        new RubricLevel('', $this->tenantA, '', 'Poor', null, 0.0),
                    ],
                ),
                new RubricCriterion(
                    id: '',
                    tenantId: $this->tenantA,
                    rubricId: '',
                    title: 'Structure',
                    description: null,
                    maxPoints: $statedMaxPoints,
                    levels: [
                        new RubricLevel('', $this->tenantA, '', 'Clear', null, 3.0),
                        new RubricLevel('', $this->tenantA, '', 'Muddled', null, 1.0),
                    ],
                ),
            ],
        );
    }

    /**
     * A submitted attempt, made directly.
     *
     * These tests are about who is marking what, not about how a paper comes
     * to need marking, so the attempt is inserted rather than sat.
     */
    private function createAttempt(): string
    {
        $assessmentId = (string) $this->db()->fetchOne(
            "INSERT INTO assessments (tenant_id, title, kind, status)
             VALUES (:tenant, :title, 'quiz', 'published') RETURNING id",
            ['tenant' => $this->tenantA, 'title' => 'Paper ' . substr(Uuid::uuid4()->toString(), 0, 8)],
        );

        return (string) $this->db()->fetchOne(
            "INSERT INTO attempts (tenant_id, assessment_id, user_id, attempt_number, status, submitted_at)
             VALUES (:tenant, :assessment, :user, 1, 'grading', NOW()) RETURNING id",
            [
                'tenant'     => $this->tenantA,
                'assessment' => $assessmentId,
                'user'       => $this->marker,
            ],
        );
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();

        $this->db()->executeStatement(
            'INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, \'active\')',
            ['id' => $id, 'name' => $prefix . ' test tenant', 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $tenantId, string $who): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO users (tenant_id, email, first_name, last_name, status)
             VALUES (:tenant, :email, :first, 'Tester', 'active') RETURNING id",
            [
                'tenant' => $tenantId,
                'email'  => $who . '-' . substr(Uuid::uuid4()->toString(), 0, 8) . '@rubric.test',
                'first'  => ucfirst($who),
            ],
        );
    }

    private function db(): Connection
    {
        return self::$connection ?? throw new \RuntimeException('No connection.');
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
