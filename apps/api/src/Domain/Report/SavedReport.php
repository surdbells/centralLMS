<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * A named set of criteria somebody wants back.
 *
 * The criteria are stored as they were entered — never with a tenant. A saved
 * report is re-run by whoever opens it, under *their* scope, so sharing one
 * with a colleague cannot hand them rows they are not entitled to. That is why
 * {@see ReportCriteria::toArray()} has no tenant in it to save.
 */
final readonly class SavedReport
{
    /**
     * @param array<string, mixed> $criteria
     */
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $userId,
        public ReportKey $key,
        public string $name,
        public ?string $description = null,
        public array $criteria = [],
        public bool $isShared = false,
        public ?ReportSchedule $schedule = null,
        public ?\DateTimeImmutable $createdAt = null,
        /** Newest run, derived rather than stored. */
        public ?ReportRun $lastRun = null,
    ) {
    }

    /**
     * Whether this person may open it.
     *
     * Ownership or sharing. The report's own permission is checked separately
     * when it runs — sharing a saved report shares the question, not the right
     * to ask it.
     */
    public function isVisibleTo(string $userId): bool
    {
        return $this->userId === $userId || $this->isShared;
    }

    /**
     * Whether this person may change or delete it.
     *
     * Only the owner. A shared report that any reader can edit is one whose
     * owner finds it quietly changed underneath them.
     */
    public function isEditableBy(string $userId): bool
    {
        return $this->userId === $userId;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string> $violations
     */
    public static function fromInput(
        string $tenantId,
        string $userId,
        ?string $id,
        array $input,
        array &$violations,
    ): ?self {
        $key = ReportKey::tryFrom(trim((string) ($input['reportKey'] ?? '')));

        if ($key === null) {
            $violations['reportKey'] = 'Not a report that exists.';
        }

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            $violations['name'] = 'Give it a name you will recognise later.';
        }

        if (mb_strlen($name) > 255) {
            $violations['name'] = 'Keep the name under 255 characters.';
        }

        $schedule = null;

        // A schedule is optional; a malformed one is not ignored.
        if (isset($input['schedule']) && is_array($input['schedule'])) {
            $schedule = ReportSchedule::fromInput($input['schedule'], $violations);
        }

        if ($violations !== []) {
            return null;
        }

        \assert($key instanceof ReportKey);

        return new self(
            id: $id,
            tenantId: $tenantId,
            userId: $userId,
            key: $key,
            name: $name,
            description: self::text($input['description'] ?? null),
            criteria: is_array($input['criteria'] ?? null) ? $input['criteria'] : [],
            isShared: (bool) ($input['isShared'] ?? false),
            schedule: $schedule,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'reportKey'   => $this->key->value,
            'name'        => $this->name,
            'description' => $this->description,
            'criteria'    => (object) $this->criteria,
            'isShared'    => $this->isShared,
            'ownerId'     => $this->userId,
            'schedule'    => $this->schedule?->toArray(),
            'lastRun'     => $this->lastRun?->toArray(),
            'nextRunAt'   => null,
            'createdAt'   => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * The payload with `nextRunAt` filled in.
     *
     * Takes the clock rather than reading one, so a listing computes it once
     * for every row and the value is the same down the page.
     *
     * @return array<string, mixed>
     */
    public function toArrayAt(\DateTimeImmutable $now): array
    {
        return [
            ...$this->toArray(),
            'nextRunAt' => $this->schedule === null || $this->schedule->paused
                ? null
                : $this->schedule->nextRunAfter($now)->format(\DateTimeInterface::ATOM),
        ];
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
