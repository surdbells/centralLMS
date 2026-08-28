<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * What one learner thought of a learning path.
 *
 * It names the enrolment it came from, and one enrolment leaves one review.
 * That is the constraint the whole thing rests on: without it a rating is a
 * number anybody can post as often as they like, and the average beside a
 * path means nothing.
 *
 * Whether it has been moderated and whether it is published are two separate
 * things. A review can be approved and later hidden; collapsing the two is how
 * a rejected review comes back at the next edit.
 */
final readonly class PathReview
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $pathId,
        public string $userId,
        public string $enrollmentId,
        public int $rating,
        public ?string $title = null,
        public ?string $reviewText = null,
        public ?int $contentRating = null,
        public ?int $difficultyRating = null,
        public ?int $practicalValueRating = null,
        public ModerationStatus $moderationStatus = ModerationStatus::Pending,
        public ?string $moderatedBy = null,
        public ?\DateTimeImmutable $moderatedAt = null,
        public ?string $rejectionReason = null,
        public bool $isPublished = false,
        public ?string $responseText = null,
        public ?string $responseBy = null,
        public ?\DateTimeImmutable $responseAt = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Joined for display. */
        public ?string $authorName = null,
        public ?string $authorAvatarUrl = null,
        public ?string $pathTitle = null,
        /** Counted from the votes, never stored. */
        public int $helpfulCount = 0,
        public int $reportedCount = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(
        string $id,
        string $tenantId,
        string $pathId,
        string $userId,
        string $enrollmentId,
        array $input,
    ): self {
        $violations = [];

        $rating = (int) ($input['rating'] ?? 0);

        if ($rating < 1 || $rating > 5) {
            $violations['rating'] = 'Give it between one and five stars.';
        }

        $optional = [
            'contentRating'        => 'content_rating',
            'difficultyRating'     => 'difficulty_rating',
            'practicalValueRating' => 'practical_value_rating',
        ];

        $scores = [];

        foreach ($optional as $key => $_) {
            $value = $input[$key] ?? null;

            if ($value === null || $value === '') {
                // Null means "did not say", which is different from a
                // middling score and should not become one.
                $scores[$key] = null;

                continue;
            }

            $score = (int) $value;

            if ($score < 1 || $score > 5) {
                $violations[$key] = 'Scores run from one to five.';
            }

            $scores[$key] = $score;
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $text = is_string($input['reviewText'] ?? null) ? trim($input['reviewText']) : null;
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : null;

        return new self(
            id: $id,
            tenantId: $tenantId,
            pathId: $pathId,
            userId: $userId,
            enrollmentId: $enrollmentId,
            rating: $rating,
            title: $title === '' ? null : $title,
            reviewText: $text === '' ? null : $text,
            contentRating: $scores['contentRating'],
            difficultyRating: $scores['difficultyRating'],
            practicalValueRating: $scores['practicalValueRating'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'pathId'       => $this->pathId,
            'pathTitle'    => $this->pathTitle,
            'userId'       => $this->userId,
            'enrollmentId' => $this->enrollmentId,
            'authorName'   => $this->authorName,
            'authorAvatarUrl' => $this->authorAvatarUrl,
            'rating'       => $this->rating,
            'title'        => $this->title,
            'reviewText'   => $this->reviewText,
            'contentRating'        => $this->contentRating,
            'difficultyRating'     => $this->difficultyRating,
            'practicalValueRating' => $this->practicalValueRating,
            'moderationStatus' => $this->moderationStatus->value,
            'moderationLabel'  => $this->moderationStatus->label(),
            'moderatedBy'      => $this->moderatedBy,
            'moderatedAt'      => $this->moderatedAt?->format(\DateTimeInterface::ATOM),
            'rejectionReason'  => $this->rejectionReason,
            'isPublished'      => $this->isPublished,
            'responseText' => $this->responseText,
            'responseBy'   => $this->responseBy,
            'responseAt'   => $this->responseAt?->format(\DateTimeInterface::ATOM),
            // Counted from the votes on every read — a stored counter cannot
            // stop the same person voting twice.
            'helpfulCount'  => $this->helpfulCount,
            'reportedCount' => $this->reportedCount,
            'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
