<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * One band a marker can pick for a criterion.
 *
 * "Excellent — 4 points. Cites at least three sources and explains each."
 * The description is the part that makes a rubric worth having: it is what
 * two markers agree on, and what a learner reads when they ask why they got a
 * three rather than a four.
 */
final readonly class RubricLevel
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $criterionId,
        public string $label,
        public ?string $description,
        public float $points,
        public int $sortOrder = 0,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $violations
     */
    public static function fromInput(
        string $id,
        string $tenantId,
        string $criterionId,
        array $input,
        array &$violations = [],
        string $prefix = 'levels',
    ): self {
        $label = trim((string) ($input['label'] ?? ''));

        if ($label === '') {
            $violations[$prefix . '.label'] = 'Give the band a name, such as "Excellent".';
        } elseif (mb_strlen($label) > 120) {
            $violations[$prefix . '.label'] = 'A band name must be 120 characters or fewer.';
        }

        $points = (float) ($input['points'] ?? 0);

        if ($points < 0) {
            $violations[$prefix . '.points'] = 'A band cannot be worth less than nothing.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $description = is_string($input['description'] ?? null)
            ? trim($input['description'])
            : null;

        return new self(
            id: $id,
            tenantId: $tenantId,
            criterionId: $criterionId,
            label: $label,
            description: $description === '' ? null : $description,
            points: $points,
            sortOrder: (int) ($input['sortOrder'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'criterionId' => $this->criterionId,
            'label'       => $this->label,
            'description' => $this->description,
            'points'      => $this->points,
            'sortOrder'   => $this->sortOrder,
        ];
    }
}
