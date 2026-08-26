<?php

declare(strict_types=1);

namespace Central\Api\Domain\Media;

/**
 * What sort of file this is, and what may be uploaded as one.
 *
 * The limits and extension lists live here rather than in the browser, because
 * a limit enforced only in JavaScript is a suggestion. The Angular
 * `MediaService` carries the same table for showing a helpful message before
 * the upload starts; this is the one that decides.
 */
enum MediaKind: string
{
    case Video    = 'video';
    case Audio    = 'audio';
    case Pdf      = 'pdf';
    case Scorm    = 'scorm';
    case Image    = 'image';
    case Document = 'document';

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Video    => ['mp4', 'webm', 'mov', 'avi', 'mkv', 'wmv', 'flv', 'm4v'],
            self::Audio    => ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'flac', 'wma'],
            self::Pdf      => ['pdf'],
            self::Scorm    => ['zip'],
            self::Image    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
            self::Document => ['doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'csv'],
        };
    }

    /** In bytes. */
    public function maxSize(): int
    {
        return match ($this) {
            self::Video    => 2_147_483_648,  // 2 GB
            self::Scorm    => 1_073_741_824,  // 1 GB
            self::Audio    => 524_288_000,    // 500 MB
            self::Pdf      => 104_857_600,    // 100 MB
            self::Document => 52_428_800,     // 50 MB
            self::Image    => 10_485_760,     // 10 MB
        };
    }

    /**
     * The kind an extension belongs to, or null if it is not accepted.
     *
     * Extension-driven rather than mime-driven: the browser's reported mime
     * type comes from the client and is trivially forged, and two of these
     * kinds — SCORM and a plain zip — share one.
     */
    public static function forExtension(string $extension): ?self
    {
        $extension = strtolower(ltrim(trim($extension), '.'));

        foreach (self::cases() as $kind) {
            if (in_array($extension, $kind->extensions(), true)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Whether a file needs unpacking before it can be played.
     */
    public function needsExtracting(): bool
    {
        return $this === self::Scorm;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pdf   => 'PDF',
            self::Scorm => 'SCORM package',
            default     => ucfirst($this->value),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }

    public static function fromLegacy(?string $value): ?self
    {
        return self::tryFrom(strtolower(trim((string) $value)));
    }
}
