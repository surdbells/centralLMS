<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * A single unit of content inside a module.
 *
 * `courseId` is carried on the row as well as `moduleId`. That is deliberate
 * denormalisation: it lets every lesson query filter by course without a join,
 * and a composite foreign key on (module_id, course_id) makes it impossible
 * for the two to disagree.
 */
final readonly class Lesson
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $courseId,
        public string $moduleId,
        public string $title,
        public LessonContentType $contentType,
        public ?string $description = null,
        public ?string $contentUrl = null,
        public ?string $contentText = null,
        public ?int $durationMinutes = null,
        public int $sortOrder = 0,
        public bool $isMandatory = true,
        public bool $isPreview = false,
        public bool $isActive = true,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
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
        string $moduleId,
        array $input,
    ): self {
        $violations = [];

        $title = trim((string) ($input['title'] ?? ''));

        if ($title === '') {
            $violations['title'] = 'Enter a lesson title.';
        } elseif (mb_strlen($title) > 255) {
            $violations['title'] = 'Title must be 255 characters or fewer.';
        }

        $contentType = null;
        $rawType = trim((string) ($input['contentType'] ?? ''));

        if ($rawType === '') {
            $violations['contentType'] = 'Choose what kind of content this lesson holds.';
        } else {
            try {
                $contentType = LessonContentType::fromLegacy($rawType);
            } catch (\ValueError) {
                $violations['contentType'] = 'Choose one of: ' . implode(', ', array_map(
                    static fn (LessonContentType $t): string => $t->value,
                    LessonContentType::cases(),
                ));
            }
        }

        $contentUrl = self::text($input['contentUrl'] ?? null);
        $contentText = self::text($input['contentText'] ?? null);

        // A lesson whose content is missing is an empty screen for the learner.
        // The database cannot express "url required when content_type = video",
        // so it is enforced here, where both values are in hand.
        if ($contentType !== null) {
            if ($contentType->requiresUrl() && $contentUrl === null) {
                $violations['contentUrl'] = sprintf(
                    'A %s lesson needs a content URL.',
                    $contentType->label(),
                );
            }

            if ($contentType->requiresText() && $contentText === null) {
                $violations['contentText'] = sprintf(
                    'A %s lesson needs its content.',
                    $contentType->label(),
                );
            }
        }

        $duration = isset($input['durationMinutes']) && $input['durationMinutes'] !== ''
            ? (int) $input['durationMinutes']
            : null;

        if ($duration !== null && $duration < 0) {
            $violations['durationMinutes'] = 'Duration cannot be negative.';
        }

        $sortOrder = (int) ($input['sortOrder'] ?? 0);

        if ($sortOrder < 0) {
            $violations['sortOrder'] = 'Position cannot be negative.';
        }

        if ($violations !== [] || $contentType === null) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            courseId: $courseId,
            moduleId: $moduleId,
            title: $title,
            contentType: $contentType,
            description: self::text($input['description'] ?? null),
            contentUrl: $contentUrl,
            contentText: $contentText,
            durationMinutes: $duration,
            sortOrder: $sortOrder,
            isMandatory: filter_var($input['isMandatory'] ?? true, FILTER_VALIDATE_BOOL),
            isPreview: filter_var($input['isPreview'] ?? false, FILTER_VALIDATE_BOOL),
            isActive: filter_var($input['isActive'] ?? true, FILTER_VALIDATE_BOOL),
        );
    }

    /**
     * Whether someone who is not enrolled may see this lesson.
     *
     * Both conditions matter: a preview lesson that has been deactivated is
     * not a preview any more.
     */
    public function isFreelyViewable(): bool
    {
        return $this->isPreview && $this->isActive;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'courseId'        => $this->courseId,
            'moduleId'        => $this->moduleId,
            'title'           => $this->title,
            'description'     => $this->description,
            'contentType'     => $this->contentType->value,
            'contentUrl'      => $this->contentUrl,
            'contentText'     => $this->contentText,
            'durationMinutes' => $this->durationMinutes,
            'sortOrder'       => $this->sortOrder,
            'isMandatory'     => $this->isMandatory,
            'isPreview'       => $this->isPreview,
            'isActive'        => $this->isActive,
            'createdAt'       => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'       => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : $value;
    }
}
