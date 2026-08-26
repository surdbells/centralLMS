<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * One learner's progress through one lesson.
 */
final readonly class LessonProgress
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $enrollmentId,
        public string $userId,
        public string $lessonId,
        public ProgressStatus $status = ProgressStatus::NotStarted,
        public float $progressPercentage = 0.0,
        public ?float $score = null,
        public int $timeSpentSeconds = 0,
        public int $lastPositionSeconds = 0,
        public ?\DateTimeImmutable $completedAt = null,
        public ?\DateTimeImmutable $lastActivityAt = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * Validates a progress report from a player.
     *
     * Returns the accepted values rather than an entity: the row may not exist
     * yet, and whether it does is the repository's business.
     *
     * @param array<string, mixed> $input
     *
     * @return array{
     *     percentage: float,
     *     status: ProgressStatus,
     *     score: ?float,
     *     secondsToAdd: int,
     *     position: ?int
     * }
     *
     * @throws ValidationFailed
     */
    public static function reportFromInput(array $input): array
    {
        $violations = [];

        $percentage = null;

        if (array_key_exists('progressPercentage', $input) && $input['progressPercentage'] !== '') {
            $percentage = (float) $input['progressPercentage'];

            if ($percentage < 0 || $percentage > 100) {
                $violations['progressPercentage'] = 'Progress must be between 0 and 100.';
            }
        }

        // `completed: true` is the shorthand a player sends when a video ends.
        if (array_key_exists('completed', $input) && filter_var($input['completed'], FILTER_VALIDATE_BOOL)) {
            $percentage = 100.0;
        }

        if ($percentage === null) {
            $violations['progressPercentage'] = 'Send a percentage, or completed: true.';
        }

        $score = null;

        if (array_key_exists('score', $input) && $input['score'] !== null && $input['score'] !== '') {
            $score = (float) $input['score'];

            if ($score < 0 || $score > 100) {
                $violations['score'] = 'A score must be between 0 and 100.';
            }
        }

        // Time is sent as an increment, not a total. A player that reports a
        // running total loses everything if the page reloads, and one that
        // reports the same total twice would double it.
        $secondsToAdd = (int) ($input['secondsSpent'] ?? 0);

        if ($secondsToAdd < 0) {
            $violations['secondsSpent'] = 'Time spent cannot be negative.';
        }

        if ($secondsToAdd > self::MAX_REPORTED_SECONDS) {
            // Twelve hours in one report is a broken clock or a forged
            // request, not a study session.
            $violations['secondsSpent'] = 'That is more time than a single session can contain.';
        }

        $position = null;

        if (array_key_exists('positionSeconds', $input) && $input['positionSeconds'] !== '') {
            $position = (int) $input['positionSeconds'];

            if ($position < 0) {
                $violations['positionSeconds'] = 'Position cannot be negative.';
            }
        }

        if ($violations !== [] || $percentage === null) {
            throw ValidationFailed::fromArray($violations);
        }

        return [
            'percentage'   => $percentage,
            // Derived, never taken from the request: percentage and status are
            // two views of one fact, and the legacy rows where they disagree
            // are what this prevents.
            'status'       => ProgressStatus::fromPercentage($percentage),
            'score'        => $score,
            'secondsToAdd' => $secondsToAdd,
            'position'     => $position,
        ];
    }

    /** Twelve hours. */
    private const MAX_REPORTED_SECONDS = 43200;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'lessonId'            => $this->lessonId,
            'status'              => $this->status->value,
            'statusLabel'         => $this->status->label(),
            'progressPercentage'  => $this->progressPercentage,
            'score'               => $this->score,
            'timeSpentSeconds'    => $this->timeSpentSeconds,
            'lastPositionSeconds' => $this->lastPositionSeconds,
            'completedAt'         => $this->completedAt?->format(\DateTimeInterface::ATOM),
            'lastActivityAt'      => $this->lastActivityAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
