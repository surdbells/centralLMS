<?php

declare(strict_types=1);

namespace Central\Api\Domain\Media;

/**
 * One uploaded file.
 *
 * A version of a file is a row here, not a separate table: version two of a
 * training video is another file with the same root. `isLatest` is answered by
 * the query rather than stored — see the migration for why.
 */
final readonly class MediaItem
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public MediaKind $kind,
        public string $title,
        public string $originalFilename,
        public string $storageKey,
        public int $fileSize,
        public string $mimeType,
        public string $extension,
        public ?string $description = null,
        public ?string $uploadedBy = null,
        public ?float $durationSeconds = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $pageCount = null,
        public ?string $scormVersion = null,
        public ?string $launchUrl = null,
        public ?string $extractedPath = null,
        public array $metadata = [],
        public ?string $sha256 = null,
        /** Null only before the row is written; every stored row has one. */
        public ?string $rootMediaId = null,
        public int $versionNumber = 1,
        public ?string $versionLabel = null,
        public ?string $versionNotes = null,
        public ?string $courseId = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Derived by the query, never stored. */
        public bool $isLatest = true,
        /** How many versions this file has, including this one. */
        public int $versionCount = 1,
        public ?string $uploaderName = null,
        public ?string $courseTitle = null,
    ) {
    }

    /**
     * Whether this row is the file itself rather than a revision of one.
     */
    public function isOriginal(): bool
    {
        return $this->rootMediaId === null || $this->rootMediaId === $this->id;
    }

    /**
     * What a player is handed.
     *
     * SCORM launches its own entry point inside the extracted package; every
     * other kind streams the stored file.
     */
    public function playbackPath(): ?string
    {
        return $this->kind === MediaKind::Scorm ? $this->launchUrl : $this->storageKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'kind'             => $this->kind->value,
            'title'            => $this->title,
            'description'      => $this->description,
            'originalFilename' => $this->originalFilename,
            'fileSize'         => $this->fileSize,
            'mimeType'         => $this->mimeType,
            'extension'        => $this->extension,
            'durationSeconds'  => $this->durationSeconds,
            'duration'         => $this->readableDuration(),
            'width'            => $this->width,
            'height'           => $this->height,
            'pageCount'        => $this->pageCount,
            'scormVersion'     => $this->scormVersion,
            // The entry file inside the extracted package, and whether the
            // package has finished unpacking — a SCORM file can be played only
            // once `scormReady` is true. Both null/false for non-SCORM.
            'launchUrl'        => $this->launchUrl,
            'scormReady'       => $this->kind === MediaKind::Scorm && $this->extractedPath !== null,
            'metadata'         => (object) $this->metadata,
            'rootMediaId'      => $this->rootMediaId,
            'versionNumber'    => $this->versionNumber,
            'versionLabel'     => $this->versionLabel,
            'versionNotes'     => $this->versionNotes,
            'versionCount'     => $this->versionCount,
            'isLatest'         => $this->isLatest,
            'courseId'         => $this->courseId,
            'courseTitle'      => $this->courseTitle,
            'uploadedBy'       => $this->uploadedBy,
            'uploaderName'     => $this->uploaderName,
            'createdAt'        => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'        => $this->updatedAt?->format(\DateTimeInterface::ATOM),
            // Where to fetch the bytes. Always through the API, never a
            // storage URL: the legacy redirected to a signed S3 link, which
            // then worked for anybody it was forwarded to, for as long as it
            // lasted, with no further check on who was asking.
            'url'              => $this->id === null ? null : '/v1/media/' . $this->id . '/file',
        ];
    }

    /**
     * A duration a person can read, or null when the kind has none.
     */
    public function readableDuration(): ?string
    {
        if ($this->durationSeconds === null) {
            return null;
        }

        $total   = (int) round($this->durationSeconds);
        $hours   = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $seconds = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds)
            : sprintf('%d:%02d', $minutes, $seconds);
    }
}
