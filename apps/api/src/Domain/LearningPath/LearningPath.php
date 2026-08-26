<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

use Central\Api\Domain\Catalog\CourseStatus;

/**
 * A programme: an ordered set of courses, assessments and sessions.
 *
 * Nineteen of the legacy's columns are not here, and all nineteen were counters
 * — `total_items`, `total_courses`, `total_assessments`, `total_enrollments`,
 * `total_completions`, `avg_completion_days`, `avg_rating`, `total_ratings` and
 * the rest. See {@see PathProgress} for what they disagreed about.
 */
final readonly class LearningPath
{
    /**
     * @param list<string> $tags
     * @param list<string> $objectives
     */
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $title,
        public string $slug,
        public CourseStatus $status = CourseStatus::Draft,
        public PathPacing $pacing = PathPacing::Sequential,
        public CompletionRule $completionRule = CompletionRule::AllRequiredItems,
        public float $completionThreshold = 100.0,
        public string $level = 'all_levels',
        public string $visibility = 'private',
        public string $enrolmentType = 'open',
        public bool $isMandatory = false,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $thumbnailUrl = null,
        public ?string $categoryId = null,
        public ?string $certificateTemplateId = null,
        public ?int $certificateValidityMonths = null,
        public ?float $estimatedHours = null,
        public ?int $maxEnrolments = null,
        public array $tags = [],
        public array $objectives = [],
        public ?string $createdBy = null,
        public ?\DateTimeImmutable $publishedAt = null,
        public ?\DateTimeImmutable $createdAt = null,
        /** Resolved for display. Read-only. */
        public ?string $categoryName = null,
        /** Derived, never stored. */
        public int $itemCount = 0,
        public int $enrolmentCount = 0,
        public int $completionCount = 0,
        public ?PathProgress $progress = null,
    ) {
    }

    /**
     * Whether a learner may see and join it.
     */
    public function isOpenToLearners(): bool
    {
        return $this->status === CourseStatus::Published && $this->visibility !== 'private';
    }

    /**
     * Whether a learner may put themselves on it.
     */
    public function allowsSelfEnrolment(): bool
    {
        return $this->isOpenToLearners()
            && $this->enrolmentType === 'open'
            && $this->visibility === 'public';
    }

    /**
     * Whether it is full.
     */
    public function isFull(): bool
    {
        return $this->maxEnrolments !== null && $this->enrolmentCount >= $this->maxEnrolments;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'title'         => $this->title,
            'slug'          => $this->slug,
            'summary'       => $this->summary,
            'description'   => $this->description,
            'thumbnailUrl'  => $this->thumbnailUrl,
            'status'        => $this->status->value,
            'pacing'        => $this->pacing->value,
            'pacingLabel'   => $this->pacing->label(),
            'level'         => $this->level,
            'visibility'    => $this->visibility,
            'enrolmentType' => $this->enrolmentType,
            'isMandatory'   => $this->isMandatory,

            'completionRule'      => $this->completionRule->value,
            'completionThreshold' => $this->completionThreshold,

            'categoryId'                => $this->categoryId,
            'categoryName'              => $this->categoryName,
            'certificateTemplateId'     => $this->certificateTemplateId,
            'certificateValidityMonths' => $this->certificateValidityMonths,
            'estimatedHours'            => $this->estimatedHours,
            'maxEnrolments'             => $this->maxEnrolments,
            'isFull'                    => $this->isFull(),

            'tags'       => $this->tags,
            'objectives' => $this->objectives,

            // Counted at read time. The legacy stored all three and every one
            // of them drifted.
            'itemCount'       => $this->itemCount,
            'enrolmentCount'  => $this->enrolmentCount,
            'completionCount' => $this->completionCount,

            'createdBy'   => $this->createdBy,
            'publishedAt' => $this->publishedAt?->format(\DateTimeInterface::ATOM),
            'createdAt'   => $this->createdAt?->format(\DateTimeInterface::ATOM),

            'progress' => $this->progress?->toArray(),
        ];
    }
}
