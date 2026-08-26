<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

/**
 * What a step in a path actually is.
 *
 * Five, where the legacy declared eight: `document` and `video` are both media,
 * and `milestone` and `checkpoint` are both a section — which the schema
 * already models, and better, because a milestone with no contents is not a
 * step somebody takes.
 */
enum PathItemKind: string
{
    case Course     = 'course';
    case Assessment = 'assessment';
    case Session    = 'session';
    case Media      = 'media';
    case Link       = 'link';

    /**
     * Whether finishing this can be established from records the platform
     * already keeps.
     *
     * The first three can: a course enrolment, an assessment attempt and an
     * attendance register all say so. The last two cannot — opening a link is
     * not something this system can observe — so they are marked instead. That
     * distinction is the whole reason `learning_path_item_completions` exists
     * and the reason the other three have no stored progress.
     */
    public function isEvidenced(): bool
    {
        return match ($this) {
            self::Course, self::Assessment, self::Session => true,
            self::Media, self::Link                       => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Course     => 'Course',
            self::Assessment => 'Assessment',
            self::Session    => 'Live session',
            self::Media      => 'Document or video',
            self::Link       => 'Link',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }

    /**
     * The legacy's eight spellings, folded onto these five.
     */
    public static function fromLegacy(?string $value): ?self
    {
        return match (strtolower(trim((string) $value))) {
            'course'                      => self::Course,
            'assessment'                  => self::Assessment,
            'live_session'                => self::Session,
            'document', 'video', 'media'  => self::Media,
            'external_link', 'link'       => self::Link,
            // `milestone` and `checkpoint` are sections, not items.
            default                       => null,
        };
    }
}
