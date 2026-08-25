<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * One participant's presence in a meeting, as reported by the platform.
 *
 * This is what feeds automatic attendance marking. The legacy system had the
 * capability (`getAttendanceReport`) but never called it, so every virtual
 * session was marked by hand or not at all.
 *
 * Matching a record back to a learner is deliberately left to the caller:
 * platforms report an email that may differ from the LMS account (personal
 * Gmail joining a Meet, a guest name with no email at all), so the matching
 * rule is a business decision, not a transport detail.
 */
final readonly class AttendanceRecord
{
    public function __construct(
        public ?string $email,
        public string $displayName,
        public \DateTimeImmutable $joinedAt,
        public ?\DateTimeImmutable $leftAt,
        public int $durationMinutes,
        public ?string $externalUserId = null,
    ) {
    }

    /**
     * Whether this participant was present long enough to count, against the
     * training's `min_virtual_duration_minutes` threshold.
     */
    public function meetsThreshold(int $minimumMinutes): bool
    {
        return $this->durationMinutes >= $minimumMinutes;
    }

    /**
     * Platforms report one row per join, so a learner who drops and rejoins
     * appears several times. Merging by identity gives the true total.
     *
     * @param list<self> $records
     *
     * @return list<self>
     */
    public static function mergeByParticipant(array $records): array
    {
        /** @var array<string, self> $merged */
        $merged = [];

        foreach ($records as $record) {
            $key = strtolower($record->email ?? $record->externalUserId ?? $record->displayName);

            if (!isset($merged[$key])) {
                $merged[$key] = $record;

                continue;
            }

            $existing = $merged[$key];

            $merged[$key] = new self(
                email: $existing->email ?? $record->email,
                displayName: $existing->displayName,
                joinedAt: min($existing->joinedAt, $record->joinedAt),
                leftAt: self::latest($existing->leftAt, $record->leftAt),
                durationMinutes: $existing->durationMinutes + $record->durationMinutes,
                externalUserId: $existing->externalUserId ?? $record->externalUserId,
            );
        }

        return array_values($merged);
    }

    private static function latest(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): ?\DateTimeImmutable
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return max($a, $b);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'email'           => $this->email,
            'displayName'     => $this->displayName,
            'joinedAt'        => $this->joinedAt->format(\DateTimeInterface::ATOM),
            'leftAt'          => $this->leftAt?->format(\DateTimeInterface::ATOM),
            'durationMinutes' => $this->durationMinutes,
        ];
    }
}
