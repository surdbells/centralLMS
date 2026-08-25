<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Shared\Difficulty;

/**
 * A course in a tenant's catalogue.
 *
 * Structure (modules and lessons) is loaded separately — the catalogue list
 * needs none of it, and the legacy endpoints selected everything every time.
 */
final readonly class Course
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $title,
        public CourseStatus $status = CourseStatus::Draft,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $objectives = null,
        public string $kind = 'regular',
        public ?Difficulty $level = null,
        public ?string $categoryId = null,
        public ?string $instructorId = null,
        public ?string $thumbnailUrl = null,
        public int $passPercentage = 70,
        public ?int $durationMinutes = null,
        public ?int $enrollmentLimit = null,
        public bool $isMandatory = false,
        public bool $isFeatured = false,
        public bool $isOnboarding = false,
        public bool $allowSelfEnrollment = false,
        public ?\DateTimeImmutable $startsOn = null,
        public ?\DateTimeImmutable $endsOn = null,
        public ?float $ratingAverage = null,
        public int $ratingCount = 0,
        public ?\DateTimeImmutable $publishedAt = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Builds a validated course from client input.
     *
     * Validation lives here so HTTP, the legacy import and any future bulk
     * upload all apply the same rules.
     *
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(string $id, string $tenantId, array $input): self
    {
        $violations = [];

        $title = trim((string) ($input['title'] ?? ''));

        if ($title === '') {
            $violations['title'] = 'Enter a course title.';
        } elseif (mb_strlen($title) > 255) {
            $violations['title'] = 'Title must be 255 characters or fewer.';
        }

        $passPercentage = (int) ($input['passPercentage'] ?? 70);

        if ($passPercentage < 0 || $passPercentage > 100) {
            $violations['passPercentage'] = 'Pass mark must be between 0 and 100.';
        }

        $level = null;

        if (isset($input['level']) && trim((string) $input['level']) !== '') {
            try {
                // fromLegacy so 'Beginner', 'beginner' and 'easy' all resolve —
                // the legacy column had drifted across all three.
                $level = Difficulty::fromLegacy((string) $input['level']);
            } catch (\ValueError) {
                $violations['level'] = 'Choose beginner, intermediate or professional.';
            }
        }

        $startsOn = self::parseDate($input['startsOn'] ?? null);
        $endsOn = self::parseDate($input['endsOn'] ?? null);

        if ($startsOn !== null && $endsOn !== null && $endsOn < $startsOn) {
            $violations['endsOn'] = 'The end date cannot be before the start date.';
        }

        $duration = isset($input['durationMinutes']) ? (int) $input['durationMinutes'] : null;

        if ($duration !== null && $duration < 0) {
            $violations['durationMinutes'] = 'Duration cannot be negative.';
        }

        $limit = isset($input['enrollmentLimit']) && $input['enrollmentLimit'] !== ''
            ? (int) $input['enrollmentLimit']
            : null;

        if ($limit !== null && $limit < 1) {
            $violations['enrollmentLimit'] = 'An enrolment limit must be at least 1, or left empty for no limit.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            title: $title,
            status: CourseStatus::fromLegacy(isset($input['status']) ? (string) $input['status'] : null),
            summary: self::text($input['summary'] ?? null),
            description: self::text($input['description'] ?? null),
            objectives: self::text($input['objectives'] ?? null),
            kind: strtolower(trim((string) ($input['kind'] ?? 'regular'))) ?: 'regular',
            level: $level,
            categoryId: self::text($input['categoryId'] ?? null),
            instructorId: self::text($input['instructorId'] ?? null),
            thumbnailUrl: self::text($input['thumbnailUrl'] ?? null),
            passPercentage: $passPercentage,
            durationMinutes: $duration,
            enrollmentLimit: $limit,
            isMandatory: filter_var($input['isMandatory'] ?? false, FILTER_VALIDATE_BOOL),
            isFeatured: filter_var($input['isFeatured'] ?? false, FILTER_VALIDATE_BOOL),
            isOnboarding: filter_var($input['isOnboarding'] ?? false, FILTER_VALIDATE_BOOL),
            allowSelfEnrollment: filter_var($input['allowSelfEnrollment'] ?? false, FILTER_VALIDATE_BOOL),
            startsOn: $startsOn,
            endsOn: $endsOn,
        );
    }

    /**
     * Whether a learner may enrol themselves right now.
     *
     * Deliberately does not consider the enrolment limit — that needs a live
     * count and belongs to the enrolments module.
     */
    public function isOpenForSelfEnrollment(\DateTimeImmutable $now): bool
    {
        if (!$this->allowSelfEnrollment || !$this->status->isVisibleToLearners()) {
            return false;
        }

        if ($this->startsOn !== null && $now < $this->startsOn) {
            return false;
        }

        return $this->endsOn === null || $now <= $this->endsOn->modify('+1 day');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'title'               => $this->title,
            'summary'             => $this->summary,
            'description'         => $this->description,
            'objectives'          => $this->objectives,
            'kind'                => $this->kind,
            'status'              => $this->status->value,
            'level'               => $this->level?->value,
            'categoryId'          => $this->categoryId,
            'instructorId'        => $this->instructorId,
            'thumbnailUrl'        => $this->thumbnailUrl,
            'passPercentage'      => $this->passPercentage,
            'durationMinutes'     => $this->durationMinutes,
            'enrollmentLimit'     => $this->enrollmentLimit,
            'isMandatory'         => $this->isMandatory,
            'isFeatured'          => $this->isFeatured,
            'isOnboarding'        => $this->isOnboarding,
            'allowSelfEnrollment' => $this->allowSelfEnrollment,
            'startsOn'            => $this->startsOn?->format('Y-m-d'),
            'endsOn'              => $this->endsOn?->format('Y-m-d'),
            'rating'              => [
                'average' => $this->ratingAverage,
                'count'   => $this->ratingCount,
            ],
            'publishedAt'         => $this->publishedAt?->format(\DateTimeInterface::ATOM),
            'createdAt'           => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'           => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
    }
}
