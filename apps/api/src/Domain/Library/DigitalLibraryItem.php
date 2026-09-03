<?php

declare(strict_types=1);

namespace Central\Api\Domain\Library;

use Central\Api\Domain\Audience\AudienceTarget;
use Central\Api\Domain\Media\MediaKind;

/**
 * One file shared through the digital library.
 *
 * It holds the original file and, for an Office document, the PDF it is
 * converted to for inline viewing. It is never handed out as a download: the
 * only address it exposes is the streaming one, and the bytes behind it are
 * served inline with a short-lived ticket.
 *
 * Its audience is a rule — a target and the ids it names — not a list of
 * people, so who can see it follows the organisation as departments and roles
 * change rather than freezing at the moment it was shared.
 */
final readonly class DigitalLibraryItem
{
    /**
     * @param array<string, list<string>> $targetCriteria e.g. ['departmentIds' => ['…']]
     * @param array<string, mixed>        $metadata
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
        public AudienceTarget $targetType,
        public array $targetCriteria = [],
        public ?string $description = null,
        public ?int $pageCount = null,
        public ?float $durationSeconds = null,
        public ?string $convertedPdfKey = null,
        public ConversionStatus $conversionStatus = ConversionStatus::NotRequired,
        public ?string $sha256 = null,
        public array $metadata = [],
        public ?string $uploadedBy = null,
        public ?string $uploaderName = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * The key of the bytes a viewer is actually shown: the converted PDF for an
     * Office document, the original otherwise. Null when a document has not
     * finished converting — there is nothing to show yet.
     */
    public function viewableKey(): ?string
    {
        if ($this->kind === MediaKind::Document) {
            return $this->convertedPdfKey; // null until conversion is done
        }

        return $this->storageKey;
    }

    /**
     * What a viewer is shown it as. An Office document is a PDF once converted,
     * so it is played through the PDF viewer, not offered as its original type.
     */
    public function viewableKind(): MediaKind
    {
        return $this->kind === MediaKind::Document ? MediaKind::Pdf : $this->kind;
    }

    public function isViewable(): bool
    {
        return $this->conversionStatus->isViewable() && $this->viewableKey() !== null;
    }

    /**
     * A short description of who this reaches, for the management list.
     */
    public function audienceLabel(): string
    {
        if ($this->targetType === AudienceTarget::All) {
            return 'Everyone';
        }

        $count = count($this->firstCriteriaList());
        $noun = $this->targetType->noun();

        return $count === 1 ? '1 ' . $noun : $count . ' ' . $noun . 's';
    }

    /**
     * The ids named by the audience, under whichever criteria key applies.
     *
     * @return list<string>
     */
    public function firstCriteriaList(): array
    {
        foreach ($this->targetType->criteriaKeys() as $key) {
            if (isset($this->targetCriteria[$key]) && is_array($this->targetCriteria[$key])) {
                return array_values(array_map(static fn ($v): string => (string) $v, $this->targetCriteria[$key]));
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'kind'             => $this->kind->value,
            // What it is shown as — a converted document views as a PDF.
            'viewKind'         => $this->viewableKind()->value,
            'title'            => $this->title,
            'description'      => $this->description,
            'originalFilename' => $this->originalFilename,
            'fileSize'         => $this->fileSize,
            'mimeType'         => $this->mimeType,
            'extension'        => $this->extension,
            'pageCount'        => $this->pageCount,
            'durationSeconds'  => $this->durationSeconds,
            'conversionStatus' => $this->conversionStatus->value,
            'viewable'         => $this->isViewable(),
            'audience'         => [
                'type'     => $this->targetType->value,
                'label'    => $this->audienceLabel(),
                'criteria' => (object) $this->targetCriteria,
            ],
            'uploadedBy'   => $this->uploadedBy,
            'uploaderName' => $this->uploaderName,
            'createdAt'    => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'    => $this->updatedAt?->format(\DateTimeInterface::ATOM),
            // The only address exposed: the streaming endpoint, inline-only.
            // There is deliberately no download url anywhere in this payload.
            'viewUrl'      => $this->id === null ? null : '/v1/library/' . $this->id . '/file',
        ];
    }
}
