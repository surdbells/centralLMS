<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Gamification\Badge;
use Central\Api\Domain\Gamification\GamificationLevel;
use Central\Api\Domain\Gamification\LeaderboardPeriod;
use Central\Api\Domain\Gamification\PointEvent;
use Central\Api\Domain\Gamification\PointReason;
use Central\Api\Infrastructure\Persistence\DbalGamificationRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Points, badges and standings against a real PostgreSQL.
 *
 * The two things that need the database are both about not paying twice: a
 * unique index on `(user, reason, subject)` for points, and one on
 * `(user, badge)` for badges. The legacy checked first and inserted after,
 * which is a race — and the reason the same badge can appear twice in
 * somebody's cabinet.
 */
#[CoversClass(DbalGamificationRepository::class)]
#[CoversClass(Badge::class)]
#[CoversClass(PointEvent::class)]
final class DbalGamificationRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalGamificationRepository $gamification;

    private string $tenantA;
    private string $tenantB;
    private string $ada;
    private string $grace;
    private string $courseId;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->gamification = new DbalGamificationRepository(self::$connection);

        $this->tenantA  = $this->createTenant('gam-a');
        $this->tenantB  = $this->createTenant('gam-b');
        $this->ada      = $this->createUser($this->tenantA, 'Ada');
        $this->grace    = $this->createUser($this->tenantA, 'Grace');
        $this->courseId = $this->createCourse();
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

    // --- points -----------------------------------------------------------

    #[Test]
    public function a_total_is_summed_from_the_ledger(): void
    {
        $this->award($this->ada, PointReason::CourseCompleted, $this->courseId);
        $this->award($this->ada, PointReason::CertificateEarned, Uuid::uuid4()->toString());

        self::assertSame(150, $this->gamification->totalPoints($this->tenantA, $this->ada));
    }

    #[Test]
    public function the_same_thing_is_not_paid_for_twice(): void
    {
        // A retried request or a double click must not pay again — and the
        // caller does not have to check first, which is what makes it safe to
        // call from a handler that may be replayed.
        self::assertNotNull($this->award($this->ada, PointReason::CourseCompleted, $this->courseId));
        self::assertNull($this->award($this->ada, PointReason::CourseCompleted, $this->courseId));

        self::assertSame(100, $this->gamification->totalPoints($this->tenantA, $this->ada));
    }

    #[Test]
    public function two_different_courses_both_pay(): void
    {
        $this->award($this->ada, PointReason::CourseCompleted, $this->courseId);
        $this->award($this->ada, PointReason::CourseCompleted, $this->createCourse('Second'));

        self::assertSame(200, $this->gamification->totalPoints($this->tenantA, $this->ada));
    }

    #[Test]
    public function a_manual_award_can_repeat(): void
    {
        // It carries no subject, and granting somebody points twice is a
        // thing an administrator may legitimately do.
        $this->manual($this->ada, 10);
        $this->manual($this->ada, 15);

        self::assertSame(25, $this->gamification->totalPoints($this->tenantA, $this->ada));
    }

    #[Test]
    public function points_can_be_taken_back_with_a_correcting_entry(): void
    {
        // A deletion would leave the history unreadable.
        $this->manual($this->ada, 100);
        $this->manual($this->ada, -40);

        self::assertSame(60, $this->gamification->totalPoints($this->tenantA, $this->ada));
    }

    #[Test]
    public function a_total_is_scoped_to_its_tenant(): void
    {
        $stranger = $this->createUser($this->tenantB, 'Stranger');

        $this->gamification->award(PointEvent::earn($this->tenantB, $stranger, PointReason::CourseCompleted));

        self::assertSame(0, $this->gamification->totalPoints($this->tenantA, $stranger));
    }

    #[Test]
    public function the_breakdown_groups_by_what_earned_it(): void
    {
        $this->award($this->ada, PointReason::CourseCompleted, $this->courseId);
        $this->award($this->ada, PointReason::CourseCompleted, $this->createCourse('Second'));
        $this->award($this->ada, PointReason::AssessmentPassed, Uuid::uuid4()->toString());

        $breakdown = $this->gamification->pointsByReason($this->tenantA, $this->ada);

        self::assertSame(200, $breakdown['course_completed']);
        self::assertSame(25, $breakdown['assessment_passed']);
    }

    #[Test]
    public function the_history_resolves_what_the_points_were_for(): void
    {
        $this->award($this->ada, PointReason::CourseCompleted, $this->courseId);

        $history = $this->gamification->history($this->tenantA, $this->ada, 1, 20);

        self::assertSame(1, $history['total']);
        self::assertSame('Fire Safety', $history['items'][0]->subjectTitle);
    }

    // --- badges -----------------------------------------------------------

    #[Test]
    public function a_badge_round_trips(): void
    {
        $badge = $this->createBadge('First Steps', points: 25);

        $read = $this->gamification->findBadge($this->tenantA, (string) $badge->id);

        self::assertSame('First Steps', $read?->name);
        self::assertSame(25, $read->pointsValue);
        self::assertSame(0, $read->awardedCount);
    }

    #[Test]
    public function awarding_a_badge_grants_its_points_in_the_same_breath(): void
    {
        // A badge awarded without its points is a discrepancy nobody can
        // explain from the ledger.
        $badge = $this->createBadge('First Steps', points: 25);

        self::assertTrue(
            $this->gamification->awardBadge($this->tenantA, $this->ada, (string) $badge->id, null),
        );

        self::assertSame(25, $this->gamification->totalPoints($this->tenantA, $this->ada));
    }

    #[Test]
    public function a_badge_is_held_once(): void
    {
        $badge = $this->createBadge('First Steps', points: 25);

        self::assertTrue($this->gamification->awardBadge($this->tenantA, $this->ada, (string) $badge->id, null));
        self::assertFalse($this->gamification->awardBadge($this->tenantA, $this->ada, (string) $badge->id, null));

        self::assertCount(1, $this->gamification->badgesFor($this->tenantA, $this->ada));
        // And its points were not granted twice either.
        self::assertSame(25, $this->gamification->totalPoints($this->tenantA, $this->ada));
    }

    #[Test]
    public function a_worthless_badge_grants_no_points(): void
    {
        $badge = $this->createBadge('Just For Show', points: 0);

        $this->gamification->awardBadge($this->tenantA, $this->ada, (string) $badge->id, null);

        self::assertSame(0, $this->gamification->totalPoints($this->tenantA, $this->ada));
        self::assertCount(1, $this->gamification->badgesFor($this->tenantA, $this->ada));
    }

    #[Test]
    public function a_badge_counts_how_many_hold_it(): void
    {
        $badge = $this->createBadge('First Steps');

        $this->gamification->awardBadge($this->tenantA, $this->ada, (string) $badge->id, null);
        $this->gamification->awardBadge($this->tenantA, $this->grace, (string) $badge->id, null);

        self::assertSame(2, $this->gamification->findBadge($this->tenantA, (string) $badge->id)?->awardedCount);
    }

    #[Test]
    public function revoking_takes_it_back(): void
    {
        $badge = $this->createBadge('First Steps');
        $this->gamification->awardBadge($this->tenantA, $this->ada, (string) $badge->id, null);

        self::assertTrue($this->gamification->revokeBadge($this->tenantA, $this->ada, (string) $badge->id));
        self::assertSame([], $this->gamification->badgesFor($this->tenantA, $this->ada));
    }

    #[Test]
    public function deleting_a_badge_is_soft(): void
    {
        // Somebody holds it, and a cabinet with a blank in it is worse than
        // one showing a badge nobody can earn any more.
        $badge = $this->createBadge('First Steps');
        $this->gamification->awardBadge($this->tenantA, $this->ada, (string) $badge->id, null);

        self::assertTrue($this->gamification->deleteBadge($this->tenantA, (string) $badge->id));

        self::assertSame(1, (int) self::$connection?->fetchOne(
            'SELECT COUNT(*) FROM badge_awards WHERE badge_id = :id',
            ['id' => $badge->id],
        ));
    }

    #[Test]
    public function a_badge_name_is_taken_only_within_its_tenant(): void
    {
        $this->createBadge('First Steps');

        self::assertTrue($this->gamification->badgeNameExists($this->tenantA, 'first STEPS'));
        self::assertFalse($this->gamification->badgeNameExists($this->tenantB, 'First Steps'));
    }

    // --- the ladder -------------------------------------------------------

    #[Test]
    public function the_ladder_comes_back_in_order(): void
    {
        $this->gamification->saveLevel($this->level(3, 'Expert', 500));
        $this->gamification->saveLevel($this->level(1, 'Beginner', 0));
        $this->gamification->saveLevel($this->level(2, 'Learner', 100));

        $ladder = $this->gamification->ladder($this->tenantA);

        self::assertSame(['Beginner', 'Learner', 'Expert'], array_map(
            static fn (GamificationLevel $l): string => $l->name,
            $ladder->levels,
        ));
    }

    #[Test]
    public function the_ladder_is_scoped_to_its_tenant(): void
    {
        $this->gamification->saveLevel($this->level(1, 'Beginner', 0));

        self::assertTrue($this->gamification->ladder($this->tenantB)->isEmpty());
    }

    // --- standings --------------------------------------------------------

    #[Test]
    public function the_leaderboard_ranks_by_total(): void
    {
        $this->manual($this->ada, 300);
        $this->manual($this->grace, 100);

        $entries = $this->gamification->leaderboard(
            $this->tenantA,
            LeaderboardPeriod::AllTime,
            new \DateTimeImmutable(),
            10,
        );

        self::assertCount(2, $entries);
        self::assertSame(1, $entries[0]->rank);
        self::assertStringContainsString('Ada', $entries[0]->name);
        self::assertSame(2, $entries[1]->rank);
    }

    #[Test]
    public function a_tie_shares_a_rank(): void
    {
        // Two people in first place are both first, and the next is third —
        // which is how a leaderboard reads.
        $this->manual($this->ada, 100);
        $this->manual($this->grace, 100);
        $third = $this->createUser($this->tenantA, 'Third');
        $this->manual($third, 50);

        $entries = $this->gamification->leaderboard(
            $this->tenantA,
            LeaderboardPeriod::AllTime,
            new \DateTimeImmutable(),
            10,
        );

        self::assertSame(1, $entries[0]->rank);
        self::assertSame(1, $entries[1]->rank);
        self::assertSame(3, $entries[2]->rank);
    }

    #[Test]
    public function the_leaderboard_marks_the_reader(): void
    {
        $this->manual($this->ada, 100);

        $entries = $this->gamification->leaderboard(
            $this->tenantA,
            LeaderboardPeriod::AllTime,
            new \DateTimeImmutable(),
            10,
            viewerId: $this->ada,
        );

        self::assertTrue($entries[0]->isViewer);
    }

    #[Test]
    public function a_period_excludes_what_fell_outside_it(): void
    {
        $this->manual($this->ada, 500, earnedAt: '-40 days');
        $this->manual($this->ada, 10);

        $monthly = $this->gamification->leaderboard(
            $this->tenantA,
            LeaderboardPeriod::Monthly,
            new \DateTimeImmutable(),
            10,
        );

        // Only what was earned this calendar month.
        self::assertSame(10, $monthly[0]->points);
        self::assertSame(510, $this->gamification->totalPoints($this->tenantA, $this->ada));
    }

    #[Test]
    public function the_leaderboard_is_scoped_to_its_tenant(): void
    {
        $stranger = $this->createUser($this->tenantB, 'Stranger');
        $this->gamification->award(new PointEvent(
            null,
            $this->tenantB,
            $stranger,
            999,
            PointReason::Manual,
            awardedBy: $stranger,
        ));
        $this->manual($this->ada, 10);

        $entries = $this->gamification->leaderboard(
            $this->tenantA,
            LeaderboardPeriod::AllTime,
            new \DateTimeImmutable(),
            10,
        );

        self::assertCount(1, $entries);
        self::assertSame(10, $entries[0]->points);
    }

    #[Test]
    public function somebody_off_the_end_can_still_be_found(): void
    {
        // A board showing the top few tells the person just outside it
        // nothing at all.
        $this->manual($this->ada, 500);
        $this->manual($this->grace, 10);

        $top = $this->gamification->leaderboard(
            $this->tenantA,
            LeaderboardPeriod::AllTime,
            new \DateTimeImmutable(),
            1,
        );

        self::assertCount(1, $top);

        $rank = $this->gamification->rankOf(
            $this->tenantA,
            $this->grace,
            LeaderboardPeriod::AllTime,
            new \DateTimeImmutable(),
        );

        self::assertSame(2, $rank?->rank);
        self::assertSame(10, $rank->points);
    }

    #[Test]
    public function somebody_with_no_points_has_no_rank(): void
    {
        self::assertNull($this->gamification->rankOf(
            $this->tenantA,
            $this->ada,
            LeaderboardPeriod::AllTime,
            new \DateTimeImmutable(),
        ));
    }

    // --- helpers ----------------------------------------------------------

    private function award(string $userId, PointReason $reason, ?string $subjectId): ?PointEvent
    {
        return $this->gamification->award(
            PointEvent::earn($this->tenantA, $userId, $reason, $subjectId),
        );
    }

    private function manual(string $userId, int $points, string $earnedAt = 'now'): void
    {
        self::$connection?->executeStatement(
            "INSERT INTO point_events (tenant_id, user_id, points, reason, awarded_by, earned_at)
             VALUES (:t, :u, :p, 'manual', :u, now() + :offset::interval)",
            ['t' => $this->tenantA, 'u' => $userId, 'p' => $points, 'offset' => $earnedAt === 'now' ? '0 days' : $earnedAt],
        );
    }

    private function createBadge(string $name, int $points = 10): Badge
    {
        return $this->gamification->saveBadge(new Badge(
            id: null,
            tenantId: $this->tenantA,
            name: $name,
            pointsValue: $points,
        ));
    }

    private function level(int $number, string $name, int $required): GamificationLevel
    {
        return new GamificationLevel(null, $this->tenantA, $number, $name, $required);
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, 'active')",
            ['id' => $id, 'name' => 'Test ' . $prefix, 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $tenantId, string $first): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status)
             VALUES (:id, :t, :e, 'x', :f, 'Tester', 'active')",
            [
                'id' => $id,
                't'  => $tenantId,
                'e'  => strtolower($first) . '-' . substr($id, 0, 8) . '@gam.test',
                'f'  => $first,
            ],
        );

        return $id;
    }

    private function createCourse(string $title = 'Fire Safety'): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO courses (id, tenant_id, title, status) VALUES (:id, :t, :ti, 'published')",
            ['id' => $id, 't' => $this->tenantA, 'ti' => $title],
        );

        return $id;
    }

    private static function connect(): ?Connection
    {
        try {
            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port'     => (int) ($_ENV['DB_PORT'] ?? 5442),
                'dbname'   => $_ENV['DB_NAME'] ?? 'central_lms',
                'user'     => $_ENV['DB_USER'] ?? 'central_lms',
                'password' => $_ENV['DB_PASSWORD'] ?? 'central_lms',
            ]);

            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }
}
