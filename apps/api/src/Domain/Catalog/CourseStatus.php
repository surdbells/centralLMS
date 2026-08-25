<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

/**
 * Publication state of a course.
 *
 * The legacy column was a `varchar(50)` holding `draft` and `published`, with
 * the frontend additionally sending `archived` and `delete`. `delete` is not a
 * status here — removal is `deleted_at`, so a deleted course cannot also be
 * "published".
 */
enum CourseStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public static function fromLegacy(?string $value): self
    {
        $value = strtolower(trim((string) $value));

        return self::tryFrom($value) ?? self::Draft;
    }

    /** Learners can see it and enrol. */
    public function isVisibleToLearners(): bool
    {
        return $this === self::Published;
    }

    /**
     * Whether the structure can still be edited.
     *
     * A published course stays editable: correcting a typo in a lesson must
     * not require unpublishing and re-enrolling everyone. Archived is the
     * frozen state.
     */
    public function isEditable(): bool
    {
        return $this !== self::Archived;
    }

    /**
     * Whether learners already enrolled keep their access.
     *
     * Archiving takes a course out of the catalogue without cutting off
     * people midway through it.
     */
    public function allowsExistingLearnersToContinue(): bool
    {
        return $this !== self::Draft;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
