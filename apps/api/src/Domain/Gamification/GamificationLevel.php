<?php

declare(strict_types=1);

namespace Central\Api\Domain\Gamification;

/**
 * One rung of the points ladder.
 *
 * Named `gamification_levels` in the schema rather than `user_levels`: the
 * legacy had two tables called something-levels meaning entirely different
 * things — `levels` is the organisational hierarchy, now `lookups(kind =
 * 'level')`, and this is the points ladder. Sharing a name is how somebody
 * joins the wrong one.
 */
final readonly class GamificationLevel
{
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public int $number,
        public string $name,
        public int $requiredPoints,
        public ?string $iconUrl = null,
        public ?string $rewards = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'number'         => $this->number,
            'name'           => $this->name,
            'requiredPoints' => $this->requiredPoints,
            'iconUrl'        => $this->iconUrl,
            'rewards'        => $this->rewards,
        ];
    }
}
