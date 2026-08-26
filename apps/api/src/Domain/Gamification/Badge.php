<?php

declare(strict_types=1);

namespace Central\Api\Domain\Gamification;

/**
 * Something worth marking.
 */
final readonly class Badge
{
    /**
     * @param array<string, mixed> $criteria what earns it automatically; empty
     *                                       means it is only ever given by hand
     */
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $name,
        public BadgeType $type = BadgeType::Achievement,
        public ?string $description = null,
        public ?string $icon = null,
        public ?string $imageUrl = null,
        public int $pointsValue = 0,
        public ?string $courseId = null,
        public array $criteria = [],
        /** How many people hold it. Counted, never stored. */
        public ?int $awardedCount = null,
        /** When the reader holds it, for a "my badges" view. */
        public ?\DateTimeImmutable $earnedAt = null,
    ) {
    }

    public function isAutomatic(): bool
    {
        return $this->criteria !== [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string> $violations
     */
    public static function fromInput(
        string $tenantId,
        ?string $id,
        array $input,
        array &$violations,
        ?self $existing = null,
    ): ?self {
        $name = trim((string) ($input['name'] ?? $existing->name ?? ''));

        if ($name === '') {
            $violations['name'] = 'A badge needs a name.';
        }

        if (mb_strlen($name) > 255) {
            $violations['name'] = 'Keep the name under 255 characters.';
        }

        $type = $existing->type ?? BadgeType::Achievement;

        if (isset($input['type'])) {
            $parsed = BadgeType::tryFrom(strtolower(trim((string) $input['type'])));

            if ($parsed === null) {
                $violations['type'] = 'Choose one of: ' . implode(', ', BadgeType::values()) . '.';
            } else {
                $type = $parsed;
            }
        }

        $points = (int) ($input['pointsValue'] ?? $existing->pointsValue ?? 0);

        if ($points < 0) {
            // A badge that takes points away is a punishment with a rosette
            // on it, which is not what anybody means by a badge.
            $violations['pointsValue'] = 'A badge cannot be worth negative points.';
        }

        $criteria = $existing->criteria ?? [];

        if (array_key_exists('criteria', $input)) {
            if ($input['criteria'] !== null && !is_array($input['criteria'])) {
                $violations['criteria'] = 'Send an object describing what earns it, or nothing.';
            } else {
                $criteria = is_array($input['criteria']) ? $input['criteria'] : [];
            }
        }

        if ($violations !== []) {
            return null;
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            name: $name,
            type: $type,
            description: self::text($input, 'description', $existing?->description),
            icon: self::text($input, 'icon', $existing?->icon),
            imageUrl: self::text($input, 'imageUrl', $existing?->imageUrl),
            pointsValue: $points,
            courseId: self::text($input, 'courseId', $existing?->courseId),
            criteria: $criteria,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'type'         => $this->type->value,
            'typeLabel'    => $this->type->label(),
            'description'  => $this->description,
            'icon'         => $this->icon,
            'imageUrl'     => $this->imageUrl,
            'pointsValue'  => $this->pointsValue,
            'courseId'     => $this->courseId,
            'criteria'     => (object) $this->criteria,
            'isAutomatic'  => $this->isAutomatic(),
            'awardedCount' => $this->awardedCount,
            'earnedAt'     => $this->earnedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function text(array $input, string $key, ?string $existing): ?string
    {
        if (!array_key_exists($key, $input)) {
            return $existing;
        }

        $value = trim((string) ($input[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
