<?php

declare(strict_types=1);

namespace Central\Api\Domain\Media;

/**
 * A file somebody is trying to upload, checked before anything is written.
 *
 * Pure: it is handed the facts about a file — name, size, declared type — and
 * decides. Nothing here touches the filesystem, which is what makes every rule
 * below testable without one.
 */
final readonly class UploadRequest
{
    private function __construct(
        public string $originalFilename,
        public string $extension,
        public MediaKind $kind,
        public int $size,
        public string $mimeType,
        public string $title,
    ) {
    }

    /**
     * @param  array<string, string> $violations
     * @param  string|null           $declaredKind what the caller says it is;
     *                                             checked against the extension
     */
    public static function check(
        string $originalFilename,
        int $size,
        string $mimeType,
        array &$violations,
        ?string $title = null,
        ?string $declaredKind = null,
    ): ?self {
        $name = trim($originalFilename);

        if ($name === '') {
            $violations['file'] = 'The upload has no filename.';

            return null;
        }

        // Only the last segment, and only the last dot. `report.pdf.exe` is
        // an exe, and a name carrying directory separators is an attempt to
        // write somewhere else.
        $base      = basename(str_replace('\\', '/', $name));
        $extension = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));

        if ($extension === '') {
            $violations['file'] = 'The file has no extension, so its type cannot be established.';

            return null;
        }

        $kind = MediaKind::forExtension($extension);

        if ($kind === null) {
            $violations['file'] = sprintf('.%s files are not accepted.', $extension);

            return null;
        }

        if ($declaredKind !== null && $declaredKind !== '') {
            $declared = MediaKind::tryFrom(strtolower(trim($declaredKind)));

            if ($declared === null) {
                $violations['kind'] = 'Choose one of: ' . implode(', ', MediaKind::values()) . '.';
            } elseif ($declared !== $kind) {
                // A .zip declared as SCORM is the one case where the caller
                // knows more than the extension does — but a .mp4 declared as
                // a PDF is a mistake worth refusing rather than storing.
                if (!($declared === MediaKind::Scorm && $extension === 'zip')) {
                    $violations['kind'] = sprintf(
                        'A .%s file is %s, not %s.',
                        $extension,
                        $kind->label(),
                        $declared->label(),
                    );
                } else {
                    $kind = MediaKind::Scorm;
                }
            }
        }

        if ($size <= 0) {
            $violations['file'] = 'The file is empty.';
        }

        if ($size > $kind->maxSize()) {
            $violations['file'] = sprintf(
                '%s files are limited to %s.',
                $kind->label(),
                self::humanSize($kind->maxSize()),
            );
        }

        if ($violations !== []) {
            return null;
        }

        return new self(
            originalFilename: $base,
            extension: $extension,
            kind: $kind,
            size: $size,
            // Kept for playback headers, and deliberately not trusted for
            // deciding what the file is — see MediaKind::forExtension().
            mimeType: trim($mimeType) === '' ? 'application/octet-stream' : trim($mimeType),
            title: self::titleFrom($title, $base),
        );
    }

    /**
     * Where the file goes.
     *
     * Built from the tenant, the kind and a random name — never from anything
     * the caller sent. A storage key derived from the uploaded filename is how
     * one tenant reads another's file by guessing, and how a crafted name
     * escapes the directory.
     */
    public function storageKey(string $tenantId, string $random): string
    {
        return sprintf('%s/%s/%s.%s', $tenantId, $this->kind->value, $random, $this->extension);
    }

    private static function titleFrom(?string $given, string $filename): string
    {
        $title = trim((string) $given);

        if ($title !== '') {
            return mb_substr($title, 0, 255);
        }

        // Falls back to the filename without its extension, which is what
        // somebody uploading twenty files at once would have typed anyway.
        return mb_substr((string) pathinfo($filename, PATHINFO_FILENAME), 0, 255);
    }

    private static function humanSize(int $bytes): string
    {
        foreach (['GB' => 1_073_741_824, 'MB' => 1_048_576, 'KB' => 1024] as $unit => $step) {
            if ($bytes >= $step) {
                return rtrim(rtrim(number_format($bytes / $step, 1, '.', ''), '0'), '.') . ' ' . $unit;
            }
        }

        return $bytes . ' bytes';
    }
}
