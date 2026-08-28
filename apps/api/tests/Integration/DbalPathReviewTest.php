<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\LearningPath\ModerationStatus;
use Central\Api\Domain\LearningPath\PathReview;
use Central\Api\Infrastructure\Persistence\DbalPathReviewRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Path reviews and moderation against real PostgreSQL.
 *
 * The things worth proving are the ones the type system cannot: that one
 * enrolment leaves one review, that an unapproved review never reaches the
 * page, that editing an approved one takes it back down, that a report puts it
 * back in the queue, and that the average is counted from what is published
 * rather than from everything written.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalPathReviewRepository::class)]
final class DbalPathReviewTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalPathReviewRepository $reviews;

    private string $tenantId;
    private string $pathId;
    private string $alice;
    private string $bob;
    private string $moderator;
    private string $aliceEnrolment;
    private string $bobEnrolment;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->reviews = new DbalPathReviewRepository(self::$connection);

        $this->tenantId = $this->createTenant('rev');
        $this->pathId = $this->createPath('Data protection');
        $this->alice = $this->createUser('alice');
        $this->bob = $this->createUser('bob');
        $this->moderator = $this->createUser('mo');
        $this->aliceEnrolment = $this->enrol($this->alice);
        $this->bobEnrolment = $this->enrol($this->bob);
    }

    protected function tearDown(): void
    {
        if (self::$connection === null || !isset($this->tenantId)) {
            return;
        }

        self::$connection->executeStatement(
            'DELETE FROM learning_path_reviews WHERE tenant_id = :id',
            ['id' => $this->tenantId],
        );
        self::$connection->executeStatement(
            'DELETE FROM learning_path_enrollments WHERE tenant_id = :id',
            ['id' => $this->tenantId],
        );
        self::$connection->executeStatement(
            'DELETE FROM learning_paths WHERE tenant_id = :id',
            ['id' => $this->tenantId],
        );
        self::$connection->executeStatement('DELETE FROM users WHERE tenant_id = :id', ['id' => $this->tenantId]);
        self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $this->tenantId]);
    }

    // --- one enrolment, one review --------------------------------------------

    #[Test]
    public function a_second_review_from_the_same_enrolment_replaces_the_first(): void
    {
        $first = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5, 'Excellent'));
        $again = $this->reviews->save(
            new PathReview(
                id: $first->id,
                tenantId: $this->tenantId,
                pathId: $this->pathId,
                userId: $this->alice,
                enrollmentId: $this->aliceEnrolment,
                rating: 2,
                title: 'On reflection',
            ),
        );

        self::assertSame($first->id, $again->id);
        self::assertSame(2, $again->rating);
        self::assertSame('On reflection', $again->title);

        // And there is still exactly one on the path.
        $this->reviews->approve($this->tenantId, $again->id, $this->moderator);
        self::assertSame(1, $this->reviews->summaryFor($this->tenantId, $this->pathId)['total']);
    }

    #[Test]
    public function the_database_refuses_a_second_review_row_for_one_enrolment(): void
    {
        $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 4));

        // Without this the average beside a path means nothing: one learner
        // could post forty ratings.
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->db()->executeStatement(
            'INSERT INTO learning_path_reviews (tenant_id, path_id, user_id, enrollment_id, rating)
             VALUES (:tenant, :path, :user, :enrolment, 1)',
            [
                'tenant'    => $this->tenantId,
                'path'      => $this->pathId,
                'user'      => $this->alice,
                'enrolment' => $this->aliceEnrolment,
            ],
        );
    }

    // --- moderation -----------------------------------------------------------

    #[Test]
    public function a_new_review_waits_to_be_checked_and_is_not_on_the_page(): void
    {
        $saved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5));

        self::assertSame(ModerationStatus::Pending, $saved->moderationStatus);
        self::assertFalse($saved->isPublished);
        self::assertSame(0, $this->reviews->published($this->tenantId, $this->pathId, 1, 25)['total']);
        self::assertSame(1, $this->reviews->pending($this->tenantId, 1, 25)['total']);
    }

    #[Test]
    public function approving_publishes_it_and_clears_the_queue(): void
    {
        $saved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5));
        $approved = $this->reviews->approve($this->tenantId, $saved->id, $this->moderator);

        self::assertNotNull($approved);
        self::assertTrue($approved->isPublished);
        self::assertSame($this->moderator, $approved->moderatedBy);
        self::assertNotNull($approved->moderatedAt);
        self::assertSame(1, $this->reviews->published($this->tenantId, $this->pathId, 1, 25)['total']);
        self::assertSame(0, $this->reviews->pending($this->tenantId, 1, 25)['total']);
    }

    #[Test]
    public function rejecting_keeps_it_down_and_keeps_the_reason(): void
    {
        $saved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 1));
        $rejected = $this->reviews->reject($this->tenantId, $saved->id, $this->moderator, 'Abusive language.');

        self::assertNotNull($rejected);
        self::assertSame(ModerationStatus::Rejected, $rejected->moderationStatus);
        self::assertFalse($rejected->isPublished);
        self::assertSame('Abusive language.', $rejected->rejectionReason);

        // And it is out of the queue rather than waiting to be judged twice.
        self::assertSame(0, $this->reviews->pending($this->tenantId, 1, 25)['total']);
    }

    #[Test]
    public function a_rejection_without_a_reason_is_refused_by_the_database(): void
    {
        $saved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 1));

        // "Rejected, no reason given" is the message the learner would get.
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);

        $this->db()->executeStatement(
            "UPDATE learning_path_reviews SET moderation_status = 'rejected' WHERE id = :id",
            ['id' => $saved->id],
        );
    }

    #[Test]
    public function editing_an_approved_review_takes_it_back_down(): void
    {
        $saved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5, 'Loved it'));
        $this->reviews->approve($this->tenantId, $saved->id, $this->moderator);

        $edited = $this->reviews->save(
            new PathReview(
                id: $saved->id,
                tenantId: $this->tenantId,
                pathId: $this->pathId,
                userId: $this->alice,
                enrollmentId: $this->aliceEnrolment,
                rating: 1,
                reviewText: 'Something a moderator never read.',
            ),
        );

        // The words a moderator approved are not the words that would be shown.
        self::assertSame(ModerationStatus::Pending, $edited->moderationStatus);
        self::assertFalse($edited->isPublished);
        self::assertNull($edited->moderatedBy);
        self::assertSame(0, $this->reviews->published($this->tenantId, $this->pathId, 1, 25)['total']);
    }

    #[Test]
    public function the_queue_is_worked_oldest_first(): void
    {
        $first = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5, 'Written first'));

        // Older by a clear margin, so the ordering is not a tie.
        $this->db()->executeStatement(
            "UPDATE learning_path_reviews SET created_at = NOW() - INTERVAL '2 days' WHERE id = :id",
            ['id' => $first->id],
        );

        $this->reviews->save($this->review($this->bob, $this->bobEnrolment, 3, 'Written second'));

        $queue = $this->reviews->pending($this->tenantId, 1, 25)['items'];

        self::assertCount(2, $queue);
        self::assertSame('Written first', $queue[0]->title);
    }

    #[Test]
    public function a_moderator_looking_at_one_path_sees_every_state(): void
    {
        $approved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5));
        $rejected = $this->reviews->save($this->review($this->bob, $this->bobEnrolment, 1));

        $this->reviews->approve($this->tenantId, $approved->id, $this->moderator);
        $this->reviews->reject($this->tenantId, $rejected->id, $this->moderator, 'Abusive.');

        // A filter that silently omitted the rejected ones would hide the very
        // thing a moderator opened the screen to find.
        self::assertSame(2, $this->reviews->forPath($this->tenantId, $this->pathId, null, 1, 25)['total']);
        self::assertSame(
            1,
            $this->reviews->forPath($this->tenantId, $this->pathId, ModerationStatus::Rejected, 1, 25)['total'],
        );

        // The learner-facing list still shows only what was published.
        self::assertSame(1, $this->reviews->published($this->tenantId, $this->pathId, 1, 25)['total']);
    }

    // --- the path's author answering ------------------------------------------

    #[Test]
    public function an_author_can_answer_a_review_in_public(): void
    {
        $saved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 2));
        $answered = $this->reviews->respond($this->tenantId, $saved->id, $this->moderator, 'Section 3 is rewritten.');

        self::assertNotNull($answered);
        self::assertSame('Section 3 is rewritten.', $answered->responseText);
        self::assertNotNull($answered->responseAt);
    }

    // --- votes ----------------------------------------------------------------

    #[Test]
    public function helpful_is_a_toggle_and_one_person_counts_once(): void
    {
        $saved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5));

        self::assertSame(1, $this->reviews->toggleHelpful($this->tenantId, $saved->id, $this->bob));
        self::assertSame(0, $this->reviews->toggleHelpful($this->tenantId, $saved->id, $this->bob));
        self::assertSame(1, $this->reviews->toggleHelpful($this->tenantId, $saved->id, $this->bob));

        // A second person is a second vote; the same person is still one.
        self::assertSame(2, $this->reviews->toggleHelpful($this->tenantId, $saved->id, $this->moderator));
    }

    #[Test]
    public function reporting_twice_is_not_un_reporting(): void
    {
        $saved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5));

        self::assertSame(1, $this->reviews->report($this->tenantId, $saved->id, $this->bob, 'Spam'));
        self::assertSame(1, $this->reviews->report($this->tenantId, $saved->id, $this->bob, 'Still spam'));

        $found = $this->reviews->find($this->tenantId, $saved->id);

        self::assertNotNull($found);
        self::assertSame(1, $found->reportedCount);
    }

    #[Test]
    public function a_reported_review_comes_off_the_page_while_somebody_looks(): void
    {
        $saved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5));
        $this->reviews->approve($this->tenantId, $saved->id, $this->moderator);

        $this->reviews->report($this->tenantId, $saved->id, $this->bob, 'Names a colleague.');

        // Leaving it up until a moderator happens to notice is what reporting
        // is meant to prevent.
        self::assertSame(0, $this->reviews->published($this->tenantId, $this->pathId, 1, 25)['total']);
        self::assertSame(1, $this->reviews->pending($this->tenantId, 1, 25)['total']);
    }

    // --- what a path is rated -------------------------------------------------

    #[Test]
    public function the_average_counts_only_what_is_published(): void
    {
        $one = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5));
        $two = $this->reviews->save($this->review($this->bob, $this->bobEnrolment, 1));

        $this->reviews->approve($this->tenantId, $one->id, $this->moderator);

        $summary = $this->reviews->summaryFor($this->tenantId, $this->pathId);

        self::assertSame(1, $summary['total']);
        self::assertSame(5.0, $summary['average']);
        self::assertSame(1, $summary['distribution'][5]);

        $this->reviews->approve($this->tenantId, $two->id, $this->moderator);

        $summary = $this->reviews->summaryFor($this->tenantId, $this->pathId);

        self::assertSame(2, $summary['total']);
        self::assertSame(3.0, $summary['average']);
    }

    #[Test]
    public function an_unreviewed_path_is_unrated_rather_than_rated_nought(): void
    {
        $summary = $this->reviews->summaryFor($this->tenantId, $this->pathId);

        // A zero would sort a brand new path below a genuinely bad one.
        self::assertSame(0, $summary['total']);
        self::assertNull($summary['average']);
    }

    #[Test]
    public function a_dimension_nobody_scored_stays_unanswered(): void
    {
        $saved = $this->reviews->save(
            new PathReview(
                id: Uuid::uuid4()->toString(),
                tenantId: $this->tenantId,
                pathId: $this->pathId,
                userId: $this->alice,
                enrollmentId: $this->aliceEnrolment,
                rating: 4,
                contentRating: 5,
            ),
        );
        $this->reviews->approve($this->tenantId, $saved->id, $this->moderator);

        $summary = $this->reviews->summaryFor($this->tenantId, $this->pathId);

        self::assertSame(5.0, $summary['averageContent']);
        // "Did not say" is not a middling score and must not become one.
        self::assertNull($summary['averageDifficulty']);
    }

    // --- withdrawing ----------------------------------------------------------

    #[Test]
    public function a_learner_can_withdraw_their_own_but_not_another_persons(): void
    {
        $saved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5));

        self::assertFalse($this->reviews->delete($this->tenantId, $saved->id, $this->bob, false));
        self::assertTrue($this->reviews->delete($this->tenantId, $saved->id, $this->alice, false));
        self::assertNull($this->reviews->find($this->tenantId, $saved->id));
    }

    #[Test]
    public function a_moderator_can_take_down_anybodys(): void
    {
        $saved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5));

        self::assertTrue($this->reviews->delete($this->tenantId, $saved->id, $this->moderator, true));
    }

    #[Test]
    public function a_withdrawn_review_frees_the_enrolment_to_review_again(): void
    {
        $saved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5));
        $this->reviews->delete($this->tenantId, $saved->id, $this->alice, false);

        // The unique index is partial on deleted_at, so this must not collide.
        $second = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 3, 'Second thoughts'));

        self::assertNotSame($saved->id, $second->id);
        self::assertSame(3, $second->rating);
    }

    // --- tenants --------------------------------------------------------------

    #[Test]
    public function reviews_do_not_cross_tenants(): void
    {
        $saved = $this->reviews->save($this->review($this->alice, $this->aliceEnrolment, 5));
        $other = Uuid::uuid4()->toString();

        self::assertNull($this->reviews->find($other, $saved->id));
        self::assertSame(0, $this->reviews->pending($other, 1, 25)['total']);
    }

    // --- helpers --------------------------------------------------------------

    private function review(
        string $userId,
        string $enrolmentId,
        int $rating,
        ?string $title = null,
    ): PathReview {
        return new PathReview(
            id: Uuid::uuid4()->toString(),
            tenantId: $this->tenantId,
            pathId: $this->pathId,
            userId: $userId,
            enrollmentId: $enrolmentId,
            rating: $rating,
            title: $title,
        );
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();

        $this->db()->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, 'active')",
            ['id' => $id, 'name' => $prefix . ' test tenant', 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $who): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO users (tenant_id, email, first_name, last_name, status)
             VALUES (:tenant, :email, :first, 'Tester', 'active') RETURNING id",
            [
                'tenant' => $this->tenantId,
                'email'  => $who . '-' . substr(Uuid::uuid4()->toString(), 0, 8) . '@rev.test',
                'first'  => ucfirst($who),
            ],
        );
    }

    private function createPath(string $title): string
    {
        return (string) $this->db()->fetchOne(
            'INSERT INTO learning_paths (tenant_id, title, slug) VALUES (:tenant, :title, :slug) RETURNING id',
            [
                'tenant' => $this->tenantId,
                'title'  => $title,
                'slug'   => 'path-' . substr(Uuid::uuid4()->toString(), 0, 8),
            ],
        );
    }

    private function enrol(string $userId): string
    {
        return (string) $this->db()->fetchOne(
            'INSERT INTO learning_path_enrollments (tenant_id, path_id, user_id)
             VALUES (:tenant, :path, :user) RETURNING id',
            ['tenant' => $this->tenantId, 'path' => $this->pathId, 'user' => $userId],
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
