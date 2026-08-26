<?php

declare(strict_types=1);

namespace Central\Api\Domain\Gamification;

/**
 * One award. The total is the sum of these, and is never written down.
 */
final readonly class PointEvent
{
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $userId,
        public int $points,
        public PointReason $reason,
        public ?string $description = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $awardedBy = null,
        public ?\DateTimeImmutable $earnedAt = null,
        /** Resolved for a history view. */
        public ?string $subjectTitle = null,
    ) {
    }

    /**
     * Builds an award, taking the subject type from the reason.
     *
     * The pair has to agree — the schema rejects half a reference — so it is
     * derived here rather than trusted from a caller.
     */
    public static function earn(
        string $tenantId,
        string $userId,
        PointReason $reason,
        ?string $subjectId = null,
        ?int $points = null,
        ?string $description = null,
        ?string $awardedBy = null,
    ): self {
        $subjectType = $subjectId === null ? null : $reason->subjectType();

        return new self(
            id: null,
            tenantId: $tenantId,
            userId: $userId,
            points: $points ?? $reason->defaultPoints(),
            reason: $reason,
            description: $description,
            subjectType: $subjectType,
            subjectId: $subjectType === null ? null : $subjectId,
            awardedBy: $awardedBy,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'points'       => $this->points,
            'reason'       => $this->reason->value,
            'reasonLabel'  => $this->reason->label(),
            'description'  => $this->description,
            'subjectType'  => $this->subjectType,
            'subjectId'    => $this->subjectId,
            'subjectTitle' => $this->subjectTitle,
            'earnedAt'     => $this->earnedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
