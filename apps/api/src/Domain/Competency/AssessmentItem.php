<?php

declare(strict_types=1);

namespace Central\Api\Domain\Competency;

/**
 * One competency inside an assessment: what the job needs, what the person
 * claimed, what the expert confirmed, and the gap that follows.
 */
final readonly class AssessmentItem
{
    public function __construct(
        public string $competencyId,
        public ProficiencyLevel $requiredLevel,
        public ?ProficiencyLevel $selfLevel = null,
        public ?ProficiencyLevel $assessedLevel = null,
        public ?string $comment = null,
        public bool $isCritical = false,
        /** Joined for display. */
        public ?string $competencyName = null,
        public ?string $functionName = null,
        /**
         * The line manager's confirming level, where they have set one. As the
         * final stage, it is what the gap is measured against where present,
         * falling back to the SME's level and then the self-claim.
         */
        public ?ProficiencyLevel $lineExecutiveLevel = null,
    ) {
    }

    /**
     * The gap: required minus what is confirmed, never below nought.
     *
     * The line manager's level where there is one, the SME's next, the self
     * level until then, nought assumed if none — an unanswered competency is a
     * full gap, not a missing row, because "not assessed" is not "already have
     * it".
     */
    public function gap(): int
    {
        return max(0, $this->requiredLevel->value - $this->demonstratedLevel()->value);
    }

    public function category(): GapCategory
    {
        return GapCategory::fromGap($this->gap());
    }

    /**
     * The level the person is credited with: the line manager's where
     * confirmed, the SME's next, the self-claim until then, none if none. The
     * other side of {@see gap()}.
     */
    public function demonstratedLevel(): ProficiencyLevel
    {
        return $this->lineExecutiveLevel
            ?? $this->assessedLevel
            ?? $this->selfLevel
            ?? ProficiencyLevel::None;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'competencyId'   => $this->competencyId,
            'competencyName' => $this->competencyName,
            'functionName'   => $this->functionName,
            'requiredLevel'  => $this->requiredLevel->value,
            'requiredLabel'  => $this->requiredLevel->label(),
            'selfLevel'      => $this->selfLevel?->value,
            'selfLabel'      => $this->selfLevel?->label(),
            'assessedLevel'  => $this->assessedLevel?->value,
            'assessedLabel'  => $this->assessedLevel?->label(),
            'lineExecutiveLevel' => $this->lineExecutiveLevel?->value,
            'lineExecutiveLabel' => $this->lineExecutiveLevel?->label(),
            'demonstratedLevel' => $this->demonstratedLevel()->value,
            'demonstratedLabel' => $this->demonstratedLevel()->label(),
            'gap'            => $this->gap(),
            'gapCategory'    => $this->category()->value,
            'gapLabel'       => $this->category()->label(),
            'estimatedMonths' => $this->category()->estimatedMonths(),
            'isCritical'     => $this->isCritical,
            'comment'        => $this->comment,
        ];
    }
}
