<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * A named part of an assessment.
 *
 * Optional: a single-part assessment needs no section row at all. Where they
 * exist they carry their own instructions, may run to their own clock, and may
 * ask a sample of their questions rather than all of them.
 */
final readonly class AssessmentSection
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $assessmentId,
        public string $title,
        public ?string $description = null,
        public ?string $instructions = null,
        public ?int $timeLimitMinutes = null,
        public SectionSelection $selectionType = SectionSelection::All,
        public ?int $randomCount = null,
        public int $sortOrder = 0,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Counted on read, never stored — see the migration. */
        public int $questionCount = 0,
        public float $totalPoints = 0.0,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(string $id, string $tenantId, string $assessmentId, array $input): self
    {
        $violations = [];

        $title = trim((string) ($input['title'] ?? ''));

        if ($title === '') {
            $violations['title'] = 'Enter a section title.';
        } elseif (mb_strlen($title) > 255) {
            $violations['title'] = 'Title must be 255 characters or fewer.';
        }

        $selection = SectionSelection::tryFrom(
            strtolower(trim((string) ($input['selectionType'] ?? 'all'))),
        ) ?? SectionSelection::All;

        $randomCount = null;

        if (isset($input['randomCount']) && $input['randomCount'] !== '' && is_numeric($input['randomCount'])) {
            $randomCount = (int) $input['randomCount'];
        }

        if ($selection === SectionSelection::Random) {
            if ($randomCount === null || $randomCount < 1) {
                // Drawing a sample of unspecified size draws nothing, and an
                // empty section scores everyone zero out of zero.
                $violations['randomCount'] = 'Say how many questions to draw.';
            }
        } else {
            // Kept out of the row entirely rather than ignored, so the
            // database constraint and this agree.
            $randomCount = null;
        }

        $timeLimit = null;

        if (isset($input['timeLimitMinutes']) && $input['timeLimitMinutes'] !== '') {
            $timeLimit = (int) $input['timeLimitMinutes'];

            if ($timeLimit < 1) {
                $violations['timeLimitMinutes'] = 'A time limit must be at least one minute, or left empty.';
            }
        }

        $sortOrder = (int) ($input['sortOrder'] ?? 0);

        if ($sortOrder < 0) {
            $violations['sortOrder'] = 'Position cannot be negative.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            assessmentId: $assessmentId,
            title: $title,
            description: self::text($input['description'] ?? null),
            instructions: self::text($input['instructions'] ?? null),
            timeLimitMinutes: $timeLimit,
            selectionType: $selection,
            randomCount: $randomCount,
            sortOrder: $sortOrder,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'assessmentId'  => $this->assessmentId,
            'title'         => $this->title,
            'description'   => $this->description,
            'instructions'  => $this->instructions,
            'timeLimitMinutes' => $this->timeLimitMinutes,
            'selectionType' => $this->selectionType->value,
            'randomCount'   => $this->randomCount,
            'sortOrder'     => $this->sortOrder,
            'questionCount' => $this->questionCount,
            'totalPoints'   => $this->totalPoints,
            'createdAt'     => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'     => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : $value;
    }
}
