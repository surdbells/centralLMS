<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * What one learner thought of a course — a 5-star evaluation.
 *
 * It names the enrolment it came from, and one enrolment leaves one review.
 * That constraint is the whole point: without it a rating is a number anybody
 * can post as often as they like, and the average beside a course means
 * nothing.
 */
final readonly class CourseReview
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $courseId,
        public string $userId,
        public string $enrollmentId,
        public int $rating,
        public ?string $title = null,
        public ?string $reviewText = null,
        public ?int $contentRating = null,
        public ?int $difficultyRating = null,
        public ?int $practicalValueRating = null,
        public bool $isPublished = true,
        public string $moderationStatus = 'approved',
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Joined for display. */
        public ?string $authorName = null,
        public ?string $courseTitle = null,
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
        string $courseId,
        string $userId,
        string $enrollmentId,
        array $input,
    ): self {
        $violations = [];

        $rating = (int) ($input['rating'] ?? 0);

        if ($rating < 1 || $rating > 5) {
            $violations['rating'] = 'Give it between one and five stars.';
        }

        $optional = ['contentRating', 'difficultyRating', 'practicalValueRating'];
        $scores   = [];

        foreach ($optional as $key) {
            $value = $input[$key] ?? null;

            if ($value === null || $value === '') {
                // Null means "did not say", which is not a middling score.
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

        $title = is_string($input['title'] ?? null) ? trim((string) $input['title']) : null;
        $text  = is_string($input['reviewText'] ?? null) ? trim((string) $input['reviewText']) : null;

        return new self(
            id: $id,
            tenantId: $tenantId,
            courseId: $courseId,
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
            'id'                   => $this->id,
            'courseId'             => $this->courseId,
            'courseTitle'          => $this->courseTitle,
            'userId'               => $this->userId,
            'enrollmentId'         => $this->enrollmentId,
            'authorName'           => $this->authorName,
            'rating'               => $this->rating,
            'title'                => $this->title,
            'reviewText'           => $this->reviewText,
            'contentRating'        => $this->contentRating,
            'difficultyRating'     => $this->difficultyRating,
            'practicalValueRating' => $this->practicalValueRating,
            'isPublished'          => $this->isPublished,
            'moderationStatus'     => $this->moderationStatus,
            'createdAt'            => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'            => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
