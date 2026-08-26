<?php

declare(strict_types=1);

namespace Central\Api\Domain\Gamification;

/**
 * One row of a leaderboard.
 */
final readonly class LeaderboardEntry
{
    public function __construct(
        public int $rank,
        public string $userId,
        public string $name,
        public int $points,
        public ?string $avatarUrl = null,
        public ?string $department = null,
        public ?GamificationLevel $level = null,
        /** True for the person reading it, so their own row can be marked. */
        public bool $isViewer = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rank'       => $this->rank,
            'userId'     => $this->userId,
            'name'       => $this->name,
            'points'     => $this->points,
            'avatarUrl'  => $this->avatarUrl,
            'department' => $this->department,
            'level'      => $this->level?->toArray(),
            'isViewer'   => $this->isViewer,
        ];
    }
}
