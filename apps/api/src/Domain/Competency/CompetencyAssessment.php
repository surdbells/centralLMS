<?php

declare(strict_types=1);

namespace Central\Api\Domain\Competency;

/**
 * One person measured against the profile of their job, in one cycle.
 *
 * Its items carry the whole finding — required, self, expert, and the gap
 * between — because an assessment without its items is a status and two dates,
 * which tells nobody what to develop.
 */
final readonly class CompetencyAssessment
{
    /**
     * @param list<AssessmentItem> $items
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $userId,
        public string $profileId,
        public string $cycle,
        public AssessmentStatus $status,
        public ?string $assessorId = null,
        public ?string $assessorComment = null,
        public ?\DateTimeImmutable $selfSubmittedAt = null,
        public ?\DateTimeImmutable $reviewedAt = null,
        public ?\DateTimeImmutable $finalizedAt = null,
        public ?\DateTimeImmutable $createdAt = null,
        public array $items = [],
        /** Joined for display. */
        public ?string $userName = null,
        public ?string $jobTitle = null,
        public ?string $functionName = null,
        public ?string $assessorName = null,
        /**
         * The narrative an individual's report carries beyond the levels: the
         * employee's own account, the manager's and executive's notes, the
         * placement decision, and what the gaps are and how they close.
         */
        public ?string $selfComment = null,
        public ?string $lineManagerComment = null,
        public ?string $lineExecutiveDecision = null,
        public ?string $gapsIdentified = null,
        public ?string $gapsClosureInterventions = null,
    ) {
    }

    /**
     * How much of what the job needs the person has — the headline figure.
     *
     * The share of requirements met, weighting each competency equally. Null
     * when the profile requires nothing, which is not a score of nought.
     */
    public function readinessPercent(): ?float
    {
        if ($this->items === []) {
            return null;
        }

        $met = count(array_filter($this->items, static fn (AssessmentItem $i): bool => $i->gap() === 0));

        return round(100 * $met / count($this->items), 1);
    }

    /**
     * @return array<string, int>
     */
    public function gapCounts(): array
    {
        $counts = [
            GapCategory::Met->value         => 0,
            GapCategory::Minor->value       => 0,
            GapCategory::Major->value       => 0,
            GapCategory::Significant->value => 0,
        ];

        foreach ($this->items as $item) {
            ++$counts[$item->category()->value];
        }

        return $counts;
    }

    /** The sum of the levels the job requires — the report's "ideal" total. */
    public function idealProficiencyTotal(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->requiredLevel->value;
        }

        return $total;
    }

    /** The sum of the levels the person is credited with — the "assessed" total. */
    public function assessedProficiencyTotal(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->demonstratedLevel()->value;
        }

        return $total;
    }

    /** The estimated months to close every gap in this assessment. */
    public function estimatedMonths(): int
    {
        $total = 0;

        foreach ($this->items as $item) {
            $total += $item->category()->estimatedMonths();
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'userId'    => $this->userId,
            'userName'  => $this->userName,
            'profileId' => $this->profileId,
            'jobTitle'  => $this->jobTitle,
            'functionName' => $this->functionName,
            'cycle'     => $this->cycle,
            'status'    => $this->status->value,
            'statusLabel' => $this->status->label(),
            'assessorId'   => $this->assessorId,
            'assessorName' => $this->assessorName,
            'assessorComment' => $this->assessorComment,
            'readinessPercent' => $this->readinessPercent(),
            'gapCounts'        => $this->gapCounts(),
            'estimatedMonths'  => $this->estimatedMonths(),
            'idealProficiencyTotal'    => $this->idealProficiencyTotal(),
            'assessedProficiencyTotal' => $this->assessedProficiencyTotal(),
            'selfComment'              => $this->selfComment,
            'lineManagerComment'       => $this->lineManagerComment,
            'lineExecutiveDecision'    => $this->lineExecutiveDecision,
            'gapsIdentified'           => $this->gapsIdentified,
            'gapsClosureInterventions' => $this->gapsClosureInterventions,
            'items'     => array_map(static fn (AssessmentItem $i): array => $i->toArray(), $this->items),
            'selfSubmittedAt' => $this->selfSubmittedAt?->format(\DateTimeInterface::ATOM),
            'reviewedAt'      => $this->reviewedAt?->format(\DateTimeInterface::ATOM),
            'finalizedAt'     => $this->finalizedAt?->format(\DateTimeInterface::ATOM),
            'createdAt'       => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
