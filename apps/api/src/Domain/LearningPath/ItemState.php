<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

/**
 * One item, as it stands for one learner.
 *
 * Derived on read. The legacy kept a row per learner per item carrying
 * `status`, `progress_percentage`, `best_score`, `passed` and `attempts`
 * *beside* the `course_enrollment_id` and `assessment_attempt_id` that already
 * held all five. Every one of its fourteen rows says `locked` with both
 * foreign keys null.
 */
final readonly class ItemState
{
    public function __construct(
        public PathItem $item,
        public bool $isComplete,
        public bool $isAvailable,
        public ?string $completedAt = null,
    ) {
    }

    /**
     * What a player should show: open, done, or not yet.
     */
    public function status(): string
    {
        return match (true) {
            $this->isComplete   => 'completed',
            $this->isAvailable  => 'available',
            default             => 'locked',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->item->toArray(),
            'status'      => $this->status(),
            'isComplete'  => $this->isComplete,
            'isAvailable' => $this->isAvailable,
            'completedAt' => $this->completedAt,
        ];
    }
}
