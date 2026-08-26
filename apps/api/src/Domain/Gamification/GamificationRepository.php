<?php

declare(strict_types=1);

namespace Central\Api\Domain\Gamification;

/**
 * Storage for points, badges and the ladder.
 */
interface GamificationRepository
{
    // --- points -----------------------------------------------------------

    /**
     * Records an award.
     *
     * Returns null when the same thing has already been paid for — a unique
     * index covers `(user, reason, subject)`, so replaying a course completion
     * cannot pay twice. The caller does not need to check first, which is what
     * makes it safe to call from a request handler that may be retried.
     */
    public function award(PointEvent $event): ?PointEvent;

    /**
     * Somebody's total. Summed, never stored.
     */
    public function totalPoints(string $tenantId, string $userId): int;

    /**
     * The total broken down by what earned it.
     *
     * @return array<string, int> keyed by {@see PointReason} value
     */
    public function pointsByReason(string $tenantId, string $userId): array;

    /**
     * @return array{items: list<PointEvent>, total: int}
     */
    public function history(string $tenantId, string $userId, int $page, int $perPage): array;

    // --- the ladder -------------------------------------------------------

    public function ladder(string $tenantId): LevelLadder;

    public function saveLevel(GamificationLevel $level): GamificationLevel;

    public function deleteLevel(string $tenantId, string $id): bool;

    // --- badges -----------------------------------------------------------

    /**
     * @return array{items: list<Badge>, total: int}
     */
    public function badges(string $tenantId, int $page, int $perPage): array;

    public function findBadge(string $tenantId, string $id): ?Badge;

    public function saveBadge(Badge $badge): Badge;

    public function deleteBadge(string $tenantId, string $id): bool;

    public function badgeNameExists(string $tenantId, string $name, ?string $exceptId = null): bool;

    /**
     * The badges one person holds, newest first.
     *
     * @return list<Badge>
     */
    public function badgesFor(string $tenantId, string $userId): array;

    /**
     * Awards a badge, and the points it carries, in one transaction.
     *
     * Returns false when they already hold it. A unique index settles that
     * rather than a check followed by an insert — the legacy's `awardBadge()`
     * did the latter, which is a race and the reason the same badge can appear
     * twice in somebody's cabinet.
     */
    public function awardBadge(
        string $tenantId,
        string $userId,
        string $badgeId,
        ?string $awardedBy,
        ?string $subjectId = null,
    ): bool;

    public function revokeBadge(string $tenantId, string $userId, string $badgeId): bool;

    // --- standings --------------------------------------------------------

    /**
     * @return list<LeaderboardEntry>
     */
    public function leaderboard(
        string $tenantId,
        LeaderboardPeriod $period,
        \DateTimeImmutable $now,
        int $limit,
        ?string $viewerId = null,
        ?string $departmentId = null,
    ): array;

    /**
     * Where one person stands, even when they are off the end of the list.
     *
     * A leaderboard that shows the top ten and nothing else tells the person
     * in eleventh place nothing at all.
     */
    public function rankOf(
        string $tenantId,
        string $userId,
        LeaderboardPeriod $period,
        \DateTimeImmutable $now,
    ): ?LeaderboardEntry;
}
