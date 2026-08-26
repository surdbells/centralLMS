<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Gamification\Badge;
use Central\Api\Domain\Gamification\BadgeType;
use Central\Api\Domain\Gamification\GamificationLevel;
use Central\Api\Domain\Gamification\GamificationRepository;
use Central\Api\Domain\Gamification\LeaderboardEntry;
use Central\Api\Domain\Gamification\LeaderboardPeriod;
use Central\Api\Domain\Gamification\LevelLadder;
use Central\Api\Domain\Gamification\PointEvent;
use Central\Api\Domain\Gamification\PointReason;
use Doctrine\DBAL\Connection;

final class DbalGamificationRepository implements GamificationRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    // --- points -----------------------------------------------------------

    public function award(PointEvent $event): ?PointEvent
    {
        // ON CONFLICT rather than a check followed by an insert: the same
        // course completion arriving twice — a retried request, a double
        // click — must not pay twice, and a check first is a race.
        $id = $this->connection->fetchOne(
            'INSERT INTO point_events
                (tenant_id, user_id, points, reason, description, subject_type, subject_id, awarded_by)
             VALUES (:tenant, :user, :points, :reason, :description, :subjectType, :subjectId, :awardedBy)
             ON CONFLICT DO NOTHING
             RETURNING id',
            [
                'tenant'      => $event->tenantId,
                'user'        => $event->userId,
                'points'      => $event->points,
                'reason'      => $event->reason->value,
                'description' => $event->description,
                'subjectType' => $event->subjectType,
                'subjectId'   => $event->subjectId,
                'awardedBy'   => $event->awardedBy,
            ],
        );

        if ($id === false || $id === null) {
            return null;
        }

        return new PointEvent(
            id: (string) $id,
            tenantId: $event->tenantId,
            userId: $event->userId,
            points: $event->points,
            reason: $event->reason,
            description: $event->description,
            subjectType: $event->subjectType,
            subjectId: $event->subjectId,
            awardedBy: $event->awardedBy,
            earnedAt: new \DateTimeImmutable(),
        );
    }

    public function totalPoints(string $tenantId, string $userId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(points), 0) FROM point_events
             WHERE tenant_id = :tenant AND user_id = :user',
            ['tenant' => $tenantId, 'user' => $userId],
        );
    }

    public function pointsByReason(string $tenantId, string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT reason, SUM(points) AS points FROM point_events
             WHERE tenant_id = :tenant AND user_id = :user
             GROUP BY reason',
            ['tenant' => $tenantId, 'user' => $userId],
        );

        $breakdown = [];

        foreach ($rows as $row) {
            $breakdown[(string) $row['reason']] = (int) $row['points'];
        }

        return $breakdown;
    }

    public function history(string $tenantId, string $userId, int $page, int $perPage): array
    {
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM point_events WHERE tenant_id = :tenant AND user_id = :user',
            ['tenant' => $tenantId, 'user' => $userId],
        );

        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.*,
                    COALESCE(c.title, l.title, a.title, cert.title, s.title) AS subject_title
             FROM point_events p
             LEFT JOIN courses c     ON p.subject_type = 'course'           AND c.id = p.subject_id
             LEFT JOIN lessons l     ON p.subject_type = 'lesson'           AND l.id = p.subject_id
             LEFT JOIN assessments a ON p.subject_type = 'assessment'       AND a.id = p.subject_id
             LEFT JOIN certificates cert ON p.subject_type = 'certificate'  AND cert.id = p.subject_id
             LEFT JOIN training_sessions s ON p.subject_type = 'training_session' AND s.id = p.subject_id
             WHERE p.tenant_id = :tenant AND p.user_id = :user
             ORDER BY p.earned_at DESC, p.id
             LIMIT :limit OFFSET :offset",
            [
                'tenant' => $tenantId,
                'user'   => $userId,
                'limit'  => $perPage,
                'offset' => ($page - 1) * $perPage,
            ],
        );

        return [
            'items' => array_map(fn (array $row): PointEvent => $this->hydrateEvent($row), $rows),
            'total' => $total,
        ];
    }

    // --- the ladder -------------------------------------------------------

    public function ladder(string $tenantId): LevelLadder
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM gamification_levels
             WHERE tenant_id = :tenant AND deleted_at IS NULL
             ORDER BY required_points',
            ['tenant' => $tenantId],
        );

        return new LevelLadder(array_map(
            fn (array $row): GamificationLevel => $this->hydrateLevel($row),
            $rows,
        ));
    }

    public function saveLevel(GamificationLevel $level): GamificationLevel
    {
        $params = [
            'tenant'   => $level->tenantId,
            'number'   => $level->number,
            'name'     => $level->name,
            'required' => $level->requiredPoints,
            'icon'     => $level->iconUrl,
            'rewards'  => $level->rewards,
        ];

        if ($level->id === null) {
            $id = (string) $this->connection->fetchOne(
                'INSERT INTO gamification_levels
                    (tenant_id, number, name, required_points, icon_url, rewards)
                 VALUES (:tenant, :number, :name, :required, :icon, :rewards)
                 RETURNING id',
                $params,
            );
        } else {
            $id = $level->id;

            $this->connection->executeStatement(
                'UPDATE gamification_levels
                 SET number = :number, name = :name, required_points = :required,
                     icon_url = :icon, rewards = :rewards, updated_at = now()
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                [...$params, 'id' => $id],
            );
        }

        return new GamificationLevel(
            $id,
            $level->tenantId,
            $level->number,
            $level->name,
            $level->requiredPoints,
            $level->iconUrl,
            $level->rewards,
        );
    }

    public function deleteLevel(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE gamification_levels SET deleted_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    // --- badges -----------------------------------------------------------

    public function badges(string $tenantId, int $page, int $perPage): array
    {
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM badges WHERE tenant_id = :tenant AND deleted_at IS NULL',
            ['tenant' => $tenantId],
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.*,
                    (SELECT COUNT(*) FROM badge_awards a WHERE a.badge_id = b.id) AS awarded_count
             FROM badges b
             WHERE b.tenant_id = :tenant AND b.deleted_at IS NULL
             ORDER BY b.name
             LIMIT :limit OFFSET :offset',
            ['tenant' => $tenantId, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $row): Badge => $this->hydrateBadge($row), $rows),
            'total' => $total,
        ];
    }

    public function findBadge(string $tenantId, string $id): ?Badge
    {
        $row = $this->connection->fetchAssociative(
            'SELECT b.*,
                    (SELECT COUNT(*) FROM badge_awards a WHERE a.badge_id = b.id) AS awarded_count
             FROM badges b
             WHERE b.id = :id AND b.tenant_id = :tenant AND b.deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateBadge($row);
    }

    public function saveBadge(Badge $badge): Badge
    {
        $params = [
            'tenant'      => $badge->tenantId,
            'name'        => $badge->name,
            'description' => $badge->description,
            'icon'        => $badge->icon,
            'imageUrl'    => $badge->imageUrl,
            'type'        => $badge->type->value,
            'points'      => $badge->pointsValue,
            'courseId'    => $badge->courseId,
            // JSON_FORCE_OBJECT: an empty PHP array encodes as [] and the
            // column insists on an object.
            'criteria'    => json_encode($badge->criteria, JSON_FORCE_OBJECT),
        ];

        if ($badge->id === null) {
            $id = (string) $this->connection->fetchOne(
                'INSERT INTO badges
                    (tenant_id, name, description, icon, image_url, type, points_value, course_id, criteria)
                 VALUES (:tenant, :name, :description, :icon, :imageUrl, :type, :points, :courseId, :criteria::jsonb)
                 RETURNING id',
                $params,
            );
        } else {
            $id = $badge->id;

            $this->connection->executeStatement(
                'UPDATE badges SET
                    name = :name, description = :description, icon = :icon, image_url = :imageUrl,
                    type = :type, points_value = :points, course_id = :courseId,
                    criteria = :criteria::jsonb, updated_at = now()
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                [...$params, 'id' => $id],
            );
        }

        return $this->findBadge($badge->tenantId, $id)
            ?? throw new \RuntimeException('The badge vanished immediately after being written.');
    }

    public function deleteBadge(string $tenantId, string $id): bool
    {
        // Soft: somebody holds it, and a badge cabinet with a blank in it is
        // worse than one showing a badge nobody can earn any more.
        return $this->connection->executeStatement(
            'UPDATE badges SET deleted_at = now() WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function badgeNameExists(string $tenantId, string $name, ?string $exceptId = null): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM badges
             WHERE tenant_id = :tenant AND lower(btrim(name)) = lower(btrim(:name))
               AND deleted_at IS NULL
               AND (:exceptId::uuid IS NULL OR id <> :exceptId::uuid)',
            ['tenant' => $tenantId, 'name' => $name, 'exceptId' => $exceptId],
        ) > 0;
    }

    public function badgesFor(string $tenantId, string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.*, a.awarded_at,
                    (SELECT COUNT(*) FROM badge_awards x WHERE x.badge_id = b.id) AS awarded_count
             FROM badge_awards a
             JOIN badges b ON b.id = a.badge_id
             WHERE a.tenant_id = :tenant AND a.user_id = :user AND b.deleted_at IS NULL
             ORDER BY a.awarded_at DESC',
            ['tenant' => $tenantId, 'user' => $userId],
        );

        return array_map(fn (array $row): Badge => $this->hydrateBadge($row), $rows);
    }

    public function awardBadge(
        string $tenantId,
        string $userId,
        string $badgeId,
        ?string $awardedBy,
        ?string $subjectId = null,
    ): bool {
        return $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $userId, $badgeId, $awardedBy, $subjectId): bool {
                $awardId = $tx->fetchOne(
                    'INSERT INTO badge_awards (tenant_id, user_id, badge_id, awarded_by, subject_type, subject_id)
                     VALUES (:tenant, :user, :badge, :by, :subjectType, :subjectId)
                     ON CONFLICT (user_id, badge_id) DO NOTHING
                     RETURNING id',
                    [
                        'tenant'      => $tenantId,
                        'user'        => $userId,
                        'badge'       => $badgeId,
                        'by'          => $awardedBy,
                        'subjectType' => $subjectId === null ? null : 'course',
                        'subjectId'   => $subjectId,
                    ],
                );

                if ($awardId === false || $awardId === null) {
                    // Already held. Not an error — two things completing at
                    // once both legitimately try.
                    return false;
                }

                $points = (int) $tx->fetchOne(
                    'SELECT points_value FROM badges WHERE id = :id AND tenant_id = :tenant',
                    ['id' => $badgeId, 'tenant' => $tenantId],
                );

                // The badge's points ride with it, in the same transaction:
                // a badge awarded without its points is a discrepancy nobody
                // can explain from the ledger.
                if ($points > 0) {
                    $tx->executeStatement(
                        "INSERT INTO point_events
                            (tenant_id, user_id, points, reason, description, awarded_by)
                         VALUES (:tenant, :user, :points, 'manual', :description, :by)",
                        [
                            'tenant'      => $tenantId,
                            'user'        => $userId,
                            'points'      => $points,
                            'description' => 'Badge awarded',
                            // A badge earned automatically still needs an
                            // actor on the points row; the system stands in.
                            'by'          => $awardedBy ?? $userId,
                        ],
                    );
                }

                return true;
            },
        );
    }

    public function revokeBadge(string $tenantId, string $userId, string $badgeId): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM badge_awards
             WHERE tenant_id = :tenant AND user_id = :user AND badge_id = :badge',
            ['tenant' => $tenantId, 'user' => $userId, 'badge' => $badgeId],
        ) > 0;
    }

    // --- standings --------------------------------------------------------

    public function leaderboard(
        string $tenantId,
        LeaderboardPeriod $period,
        \DateTimeImmutable $now,
        int $limit,
        ?string $viewerId = null,
        ?string $departmentId = null,
    ): array {
        $since = $period->since($now);

        $params = ['tenant' => $tenantId, 'limit' => $limit];
        $where  = ['p.tenant_id = :tenant', 'u.deleted_at IS NULL', "u.status = 'active'"];

        if ($since !== null) {
            $where[]         = 'p.earned_at >= :since';
            $params['since'] = $since->format('Y-m-d H:i:s.uP');
        }

        if ($departmentId !== null) {
            $where[]                = 'u.department_id = :departmentId';
            $params['departmentId'] = $departmentId;
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT u.id,
                        trim(u.first_name || ' ' || u.last_name) AS name,
                        u.avatar_url,
                        d.name AS department,
                        SUM(p.points) AS points,
                        -- Ties share a rank, and the next rank skips: two
                        -- people in second place are both second, and the
                        -- next is fourth. DENSE_RANK would make them third,
                        -- which is not how a leaderboard reads.
                        RANK() OVER (ORDER BY SUM(p.points) DESC) AS rank
                 FROM point_events p
                 JOIN users u ON u.id = p.user_id
                 LEFT JOIN lookups d ON d.id = u.department_id
                 WHERE %s
                 GROUP BY u.id, u.first_name, u.last_name, u.avatar_url, d.name
                 HAVING SUM(p.points) > 0
                 ORDER BY points DESC, u.id
                 LIMIT :limit",
                implode(' AND ', $where),
            ),
            $params,
        );

        $ladder = $this->ladder($tenantId);

        return array_map(
            static fn (array $row): LeaderboardEntry => new LeaderboardEntry(
                rank: (int) $row['rank'],
                userId: (string) $row['id'],
                name: (string) $row['name'],
                points: (int) $row['points'],
                avatarUrl: is_string($row['avatar_url'] ?? null) ? $row['avatar_url'] : null,
                department: is_string($row['department'] ?? null) ? $row['department'] : null,
                level: $ladder->progressFor((int) $row['points'])->current,
                isViewer: $viewerId !== null && (string) $row['id'] === $viewerId,
            ),
            $rows,
        );
    }

    public function rankOf(
        string $tenantId,
        string $userId,
        LeaderboardPeriod $period,
        \DateTimeImmutable $now,
    ): ?LeaderboardEntry {
        $since = $period->since($now);

        $params = ['tenant' => $tenantId, 'user' => $userId];
        $where  = ['p.tenant_id = :tenant', 'u.deleted_at IS NULL', "u.status = 'active'"];

        if ($since !== null) {
            $where[]         = 'p.earned_at >= :since';
            $params['since'] = $since->format('Y-m-d H:i:s.uP');
        }

        // The whole board is ranked and then one row picked out. Counting
        // "how many have more" would be cheaper but gets ties wrong, and the
        // person on the boundary is exactly who cares.
        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT * FROM (
                     SELECT u.id,
                            trim(u.first_name || ' ' || u.last_name) AS name,
                            u.avatar_url,
                            d.name AS department,
                            SUM(p.points) AS points,
                            RANK() OVER (ORDER BY SUM(p.points) DESC) AS rank
                     FROM point_events p
                     JOIN users u ON u.id = p.user_id
                     LEFT JOIN lookups d ON d.id = u.department_id
                     WHERE %s
                     GROUP BY u.id, u.first_name, u.last_name, u.avatar_url, d.name
                     HAVING SUM(p.points) > 0
                 ) AS standings
                 WHERE id = :user",
                implode(' AND ', $where),
            ),
            $params,
        );

        if ($row === false) {
            return null;
        }

        return new LeaderboardEntry(
            rank: (int) $row['rank'],
            userId: (string) $row['id'],
            name: (string) $row['name'],
            points: (int) $row['points'],
            avatarUrl: is_string($row['avatar_url'] ?? null) ? $row['avatar_url'] : null,
            department: is_string($row['department'] ?? null) ? $row['department'] : null,
            level: $this->ladder($tenantId)->progressFor((int) $row['points'])->current,
            isViewer: true,
        );
    }

    // --- internals --------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateEvent(array $row): PointEvent
    {
        return new PointEvent(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            userId: (string) $row['user_id'],
            points: (int) $row['points'],
            reason: PointReason::from((string) $row['reason']),
            description: $this->text($row['description'] ?? null),
            subjectType: $this->text($row['subject_type'] ?? null),
            subjectId: $this->text($row['subject_id'] ?? null),
            awardedBy: $this->text($row['awarded_by'] ?? null),
            earnedAt: $this->date($row['earned_at'] ?? null),
            subjectTitle: $this->text($row['subject_title'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateLevel(array $row): GamificationLevel
    {
        return new GamificationLevel(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            number: (int) $row['number'],
            name: (string) $row['name'],
            requiredPoints: (int) $row['required_points'],
            iconUrl: $this->text($row['icon_url'] ?? null),
            rewards: $this->text($row['rewards'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateBadge(array $row): Badge
    {
        $criteria = json_decode((string) ($row['criteria'] ?? '{}'), true);

        return new Badge(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            name: (string) $row['name'],
            type: BadgeType::from((string) $row['type']),
            description: $this->text($row['description'] ?? null),
            icon: $this->text($row['icon'] ?? null),
            imageUrl: $this->text($row['image_url'] ?? null),
            pointsValue: (int) $row['points_value'],
            courseId: $this->text($row['course_id'] ?? null),
            criteria: is_array($criteria) ? $criteria : [],
            awardedCount: isset($row['awarded_count']) ? (int) $row['awarded_count'] : null,
            earnedAt: $this->date($row['awarded_at'] ?? null),
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
