<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * One choice, match or ordered item belonging to a question.
 *
 * `matchText` carries the second half of a pair: the right-hand term for a
 * matching question. Ordering questions use `sortOrder` as the correct
 * position, so both types share this one table rather than each having their
 * own — they are the same shape with a different reading.
 */
final readonly class QuestionOption
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $questionId,
        public string $text,
        public bool $isCorrect = false,
        public ?string $feedback = null,
        public int $sortOrder = 0,
        public ?string $matchText = null,
    ) {
    }

    /**
     * The payload sent to a learner sitting the assessment.
     *
     * `isCorrect`, `feedback` and `matchText` are all absent: sending them
     * would put the answer key in the browser of the person being tested.
     * Revealing them afterwards is a separate, deliberate call — see
     * `toArrayForReview()`.
     *
     * @return array<string, mixed>
     */
    public function toArrayForLearner(): array
    {
        return [
            'id'        => $this->id,
            'text'      => $this->text,
            'sortOrder' => $this->sortOrder,
        ];
    }

    /**
     * The payload for an author, or for a learner after the answers are
     * released.
     *
     * @return array<string, mixed>
     */
    public function toArrayForReview(): array
    {
        return [
            'id'        => $this->id,
            'text'      => $this->text,
            'isCorrect' => $this->isCorrect,
            'feedback'  => $this->feedback,
            'sortOrder' => $this->sortOrder,
            'matchText' => $this->matchText,
        ];
    }
}
