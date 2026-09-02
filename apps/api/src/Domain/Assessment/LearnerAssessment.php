<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * One row of a learner's assessment catalogue: a published assessment, joined
 * to whether it is assigned to them and how their own attempts have gone.
 *
 * `isMandatory` is derived — it means "there is an assignment that reaches this
 * learner" — because the assignment domain is exactly "who must take it, and by
 * when". `prerequisites` is always empty: the new API has no prerequisite
 * schema, so the catalogue never locks a row on one.
 */
final readonly class LearnerAssessment
{
    /**
     * @param list<string> $prerequisites always [] until a prerequisite schema exists
     */
    public function __construct(
        public string $assessmentId,
        public string $title,
        public ?string $description,
        public string $kind,
        public ?string $categoryId,
        public ?string $categoryName,
        public ?string $courseId,
        public ?string $courseTitle,
        public int $questionCount,
        public float $totalPoints,
        public ?int $timeLimitMinutes,
        public ?int $maxAttempts,
        public float $passingScore,
        public string $passingType,
        public string $proctoringLevel,
        public ?string $difficulty,
        public ?\DateTimeImmutable $availableFrom,
        public ?\DateTimeImmutable $availableUntil,
        public string $enrollmentStatus,
        public int $attemptCount,
        public ?float $bestScore,
        public bool $isMandatory,
        public ?\DateTimeImmutable $dueDate,
        public array $prerequisites = [],
    ) {
    }

    /**
     * Decides the learner's standing from the boolean flags the query returns.
     *
     * Precedence: an open sitting wins, then a submission awaiting a mark, then
     * a pass, then a fail; otherwise assigned-but-unstarted, otherwise nothing.
     */
    public static function statusFrom(
        bool $hasOpen,
        bool $hasSubmitted,
        bool $hasPassed,
        bool $hasFailed,
        bool $isAssigned,
    ): string {
        return match (true) {
            $hasOpen      => 'in_progress',
            $hasSubmitted => 'completed',
            $hasPassed    => 'passed',
            $hasFailed    => 'failed',
            $isAssigned   => 'enrolled',
            default       => 'not_enrolled',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'assessmentId'     => $this->assessmentId,
            'title'            => $this->title,
            'description'      => $this->description,
            'kind'             => $this->kind,
            'categoryId'       => $this->categoryId,
            'categoryName'     => $this->categoryName,
            'courseId'         => $this->courseId,
            'courseTitle'      => $this->courseTitle,
            'questionCount'    => $this->questionCount,
            'totalPoints'      => $this->totalPoints,
            'timeLimitMinutes' => $this->timeLimitMinutes,
            'maxAttempts'      => $this->maxAttempts,
            'passingScore'     => $this->passingScore,
            'passingType'      => $this->passingType,
            'proctoringLevel'  => $this->proctoringLevel,
            'difficulty'       => $this->difficulty,
            'availableFrom'    => $this->availableFrom?->format(\DateTimeInterface::ATOM),
            'availableUntil'   => $this->availableUntil?->format(\DateTimeInterface::ATOM),
            'enrollmentStatus' => $this->enrollmentStatus,
            'attemptCount'     => $this->attemptCount,
            'bestScore'        => $this->bestScore,
            'isMandatory'      => $this->isMandatory,
            'dueDate'          => $this->dueDate?->format(\DateTimeInterface::ATOM),
            'prerequisites'    => $this->prerequisites,
        ];
    }
}
