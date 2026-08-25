<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

/**
 * What a lesson actually holds.
 *
 * The legacy column was free text and had accumulated both `doc` and `pdf`
 * for the same thing, so `fromLegacy()` folds the aliases in rather than
 * carrying two spellings forward.
 */
enum LessonContentType: string
{
    case Video = 'video';
    case Text = 'text';
    case Audio = 'audio';
    case Pdf = 'pdf';
    case Scorm = 'scorm';
    case Html = 'html';
    case Quiz = 'quiz';

    /**
     * @throws \ValueError on an unrecognised value
     */
    public static function fromLegacy(string $value): self
    {
        $value = strtolower(trim($value));

        $aliases = [
            'doc'      => 'pdf',
            'document' => 'pdf',
            'file'     => 'pdf',
            'mp4'      => 'video',
            'youtube'  => 'video',
            'vimeo'    => 'video',
            'mp3'      => 'audio',
            'article'  => 'text',
            'richtext' => 'html',
            'assessment' => 'quiz',
        ];

        return self::from($aliases[$value] ?? $value);
    }

    /**
     * Whether the content lives at a URL rather than in the row.
     *
     * A video lesson with no URL is an empty screen for the learner, so this
     * drives a validation rule rather than being advisory.
     */
    public function requiresUrl(): bool
    {
        return match ($this) {
            self::Video, self::Audio, self::Pdf, self::Scorm => true,
            self::Text, self::Html, self::Quiz => false,
        };
    }

    /**
     * Whether the content is stored inline.
     */
    public function requiresText(): bool
    {
        return match ($this) {
            self::Text, self::Html => true,
            self::Video, self::Audio, self::Pdf, self::Scorm, self::Quiz => false,
        };
    }

    /**
     * A quiz carries no content of its own — it points at an assessment, which
     * the assessments module owns. Until that lands, a quiz lesson is a
     * placeholder and neither a URL nor text is required.
     */
    public function isExternallyOwned(): bool
    {
        return $this === self::Quiz;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pdf   => 'PDF',
            self::Html  => 'HTML',
            self::Scorm => 'SCORM',
            default     => ucfirst($this->value),
        };
    }
}
