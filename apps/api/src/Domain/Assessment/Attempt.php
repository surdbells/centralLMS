<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * One sitting of an assessment by one learner.
 */
final readonly class Attempt
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $assessmentId,
        public string $userId,
        public int $attemptNumber,
        public AttemptStatus $status = AttemptStatus::InProgress,
        public ?float $rawScore = null,
        public ?float $maxScore = null,
        public ?float $percentage = null,
        public ?bool $passed = null,
        public int $timeSpentSeconds = 0,
        public ?\DateTimeImmutable $expiresAt = null,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $submittedAt = null,
        public ?\DateTimeImmutable $gradedAt = null,
        public ?string $assessmentTitle = null,
        public ?string $userName = null,
    ) {
    }

    /**
     * Whether the clock has run out.
     *
     * An expired attempt is still `in_progress` in the database until someone
     * submits or sweeps it; this is what tells the reader it should not be
     * accepting answers any more.
     */
    public function hasExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $now > $this->expiresAt;
    }

    /**
     * Whether answers may still be saved against it.
     */
    public function acceptsAnswers(\DateTimeImmutable $now): bool
    {
        return $this->status->isOpen() && !$this->hasExpired($now);
    }

    public function secondsRemaining(\DateTimeImmutable $now): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return max(0, $this->expiresAt->getTimestamp() - $now->getTimestamp());
    }

    /**
     * Why a learner may not start another attempt, or null if they may.
     *
     * Returns the reason rather than a boolean so the caller can say which
     * rule stopped them — "you have used all 3 attempts" and "you can try
     * again in 2 hours" are different messages and lead to different actions.
     *
     * @param list<Attempt> $previous every prior attempt, newest first
     *
     * @return array{code: string, message: string}|null
     */
    public static function refusalToStart(
        Assessment $assessment,
        array $previous,
        \DateTimeImmutable $now,
    ): ?array {
        if (!$assessment->status->isAvailableToLearners()) {
            return [
                'code'    => 'assessment_not_available',
                'message' => 'This assessment is not open.',
            ];
        }

        if ($assessment->availableFrom !== null && $now < $assessment->availableFrom) {
            return [
                'code'    => 'assessment_not_yet_open',
                'message' => 'This assessment opens on ' . $assessment->availableFrom->format('j F Y') . '.',
            ];
        }

        if (!$assessment->isOpenNow($now)) {
            return [
                'code'    => 'assessment_closed',
                'message' => 'The deadline for this assessment has passed.',
            ];
        }

        // Only finished sittings count against the limit. An abandoned one
        // that was never submitted should not consume an attempt.
        $used = array_filter($previous, static fn (self $a): bool => $a->status->countsAsAttempt());

        if ($assessment->maxAttempts !== null && count($used) >= $assessment->maxAttempts) {
            return [
                'code'    => 'attempts_exhausted',
                'message' => sprintf(
                    'You have used all %d attempts.',
                    $assessment->maxAttempts,
                ),
            ];
        }

        if ($assessment->attemptsGapHours > 0 && $used !== []) {
            $last = null;

            foreach ($used as $attempt) {
                $at = $attempt->submittedAt ?? $attempt->startedAt;

                if ($at !== null && ($last === null || $at > $last)) {
                    $last = $at;
                }
            }

            if ($last !== null) {
                $nextAllowed = $last->modify(sprintf('+%d hours', $assessment->attemptsGapHours));

                if ($now < $nextAllowed) {
                    return [
                        'code'    => 'attempt_too_soon',
                        'message' => 'You can try again on ' . $nextAllowed->format('j F Y \a\t H:i') . '.',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function toArray(array $extra = []): array
    {
        $now = new \DateTimeImmutable();

        return [
            'id'              => $this->id,
            'assessmentId'    => $this->assessmentId,
            'assessmentTitle' => $this->assessmentTitle,
            'userId'          => $this->userId,
            'userName'        => $this->userName,
            'attemptNumber'   => $this->attemptNumber,
            'status'          => $this->status->value,
            'statusLabel'     => $this->status->label(),
            'rawScore'        => $this->rawScore,
            'maxScore'        => $this->maxScore,
            'percentage'      => $this->percentage,
            'passed'          => $this->passed,
            'timeSpentSeconds' => $this->timeSpentSeconds,
            'expiresAt'        => $this->expiresAt?->format(\DateTimeInterface::ATOM),
            'secondsRemaining' => $this->status->isOpen() ? $this->secondsRemaining($now) : null,
            'startedAt'        => $this->startedAt?->format(\DateTimeInterface::ATOM),
            'submittedAt'      => $this->submittedAt?->format(\DateTimeInterface::ATOM),
            'gradedAt'         => $this->gradedAt?->format(\DateTimeInterface::ATOM),
            ...$extra,
        ];
    }
}
