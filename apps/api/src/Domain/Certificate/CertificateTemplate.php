<?php

declare(strict_types=1);

namespace Central\Api\Domain\Certificate;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * The HTML a certificate is rendered from.
 */
final readonly class CertificateTemplate
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $name,
        public string $bodyHtml,
        public ?string $description = null,
        public ?string $stylesCss = null,
        public string $orientation = 'landscape',
        public string $pageSize = 'A4',
        public bool $isDefault = false,
        public bool $isActive = true,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    private const ORIENTATIONS = ['portrait', 'landscape'];
    private const PAGE_SIZES = ['A4', 'A5', 'Letter', 'Legal'];

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(
        string $id,
        string $tenantId,
        array $input,
        CertificateRenderer $renderer,
    ): self {
        $violations = [];

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            $violations['name'] = 'Give the template a name.';
        } elseif (mb_strlen($name) > 255) {
            $violations['name'] = 'Name must be 255 characters or fewer.';
        }

        $bodyHtml = trim((string) ($input['bodyHtml'] ?? ''));

        if ($bodyHtml === '') {
            $violations['bodyHtml'] = 'A template needs some content.';
        } else {
            $unknown = $renderer->unknownPlaceholders($bodyHtml);

            if ($unknown !== []) {
                // Caught here so a typo is a save-time error rather than a
                // literal {{leaner_name}} printed on somebody's certificate.
                $violations['bodyHtml'] = sprintf(
                    'Unknown placeholder%s: %s. Available: %s.',
                    count($unknown) === 1 ? '' : 's',
                    implode(', ', $unknown),
                    implode(', ', CertificateRenderer::PLACEHOLDERS),
                );
            }
        }

        $orientation = strtolower(trim((string) ($input['orientation'] ?? 'landscape')));

        if (!in_array($orientation, self::ORIENTATIONS, true)) {
            $violations['orientation'] = 'Choose portrait or landscape.';
        }

        $pageSize = trim((string) ($input['pageSize'] ?? 'A4'));

        if (!in_array($pageSize, self::PAGE_SIZES, true)) {
            $violations['pageSize'] = 'Choose one of: ' . implode(', ', self::PAGE_SIZES) . '.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $description = is_string($input['description'] ?? null) ? trim($input['description']) : null;
        $css = is_string($input['stylesCss'] ?? null) ? trim($input['stylesCss']) : null;

        return new self(
            id: $id,
            tenantId: $tenantId,
            name: $name,
            bodyHtml: $bodyHtml,
            description: $description === '' ? null : $description,
            stylesCss: $css === '' ? null : $css,
            orientation: $orientation,
            pageSize: $pageSize,
            isDefault: filter_var($input['isDefault'] ?? false, FILTER_VALIDATE_BOOL),
            isActive: filter_var($input['isActive'] ?? true, FILTER_VALIDATE_BOOL),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'bodyHtml'    => $this->bodyHtml,
            'stylesCss'   => $this->stylesCss,
            'orientation' => $this->orientation,
            'pageSize'    => $this->pageSize,
            'isDefault'   => $this->isDefault,
            'isActive'    => $this->isActive,
            'createdAt'   => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
