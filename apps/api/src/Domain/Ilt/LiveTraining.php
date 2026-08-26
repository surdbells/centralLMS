<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * A training programme: one or more sessions people attend.
 */
final readonly class LiveTraining
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $title,
        public TrainingDelivery $delivery = TrainingDelivery::Virtual,
        public TrainingStatus $status = TrainingStatus::Draft,
        public ?string $description = null,
        public ?string $summary = null,
        public ?string $thumbnailUrl = null,
        public ?string $categoryId = null,
        public ?int $maxCapacity = null,
        public bool $allowWaitlist = false,
        public float $minAttendancePercentage = 100.0,
        public bool $mandatorySessionsOnly = false,
        public bool $issuesCertificate = false,
        /** @var list<int> */
        public array $reminderOffsetsMinutes = [1440, 60],
        /** @var list<string> */
        public array $reminderChannels = ['email'],
        public int $qrGracePeriodMinutes = 15,
        public int $qrValidBeforeStartMinutes = 30,
        public int $minVirtualDurationMinutes = 30,
        public ?string $createdBy = null,
        public ?\DateTimeImmutable $publishedAt = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Counted on read. */
        public int $sessionCount = 0,
        public int $enrolledCount = 0,
    ) {
    }

    private const CHANNELS = ['email', 'sms', 'in_app', 'push'];

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(string $id, string $tenantId, array $input): self
    {
        $violations = [];

        $title = trim((string) ($input['title'] ?? ''));

        if ($title === '') {
            $violations['title'] = 'Enter a title.';
        } elseif (mb_strlen($title) > 255) {
            $violations['title'] = 'Title must be 255 characters or fewer.';
        }

        $attendance = (float) ($input['minAttendancePercentage'] ?? 100);

        if ($attendance < 0 || $attendance > 100) {
            $violations['minAttendancePercentage'] = 'Required attendance must be between 0 and 100.';
        }

        $capacity = null;

        if (isset($input['maxCapacity']) && $input['maxCapacity'] !== '') {
            if (!is_numeric($input['maxCapacity']) || (int) $input['maxCapacity'] < 1) {
                $violations['maxCapacity'] = 'A capacity must be at least 1, or left empty for no limit.';
            } else {
                $capacity = (int) $input['maxCapacity'];
            }
        }

        $allowWaitlist = filter_var($input['allowWaitlist'] ?? false, FILTER_VALIDATE_BOOL);

        if ($allowWaitlist && $capacity === null) {
            // A waitlist for an unlimited programme is a queue for a room with
            // no door: nobody would ever come off it.
            $violations['allowWaitlist'] = 'A waitlist needs a capacity to be waiting for.';
        }

        $offsets = self::offsets($input['reminderOffsetsMinutes'] ?? null, $violations);
        $channels = self::channels($input['reminderChannels'] ?? null, $violations);

        $windows = [
            'qrGracePeriodMinutes'      => 15,
            'qrValidBeforeStartMinutes' => 30,
            'minVirtualDurationMinutes' => 30,
        ];

        foreach ($windows as $key => $default) {
            $value = isset($input[$key]) && $input[$key] !== '' ? (int) $input[$key] : $default;

            if ($value < 0) {
                $violations[$key] = 'This cannot be negative.';
            }
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            title: $title,
            delivery: TrainingDelivery::fromLegacy($input['delivery'] ?? null),
            status: TrainingStatus::fromLegacy($input['status'] ?? null),
            description: self::text($input['description'] ?? null),
            summary: self::text($input['summary'] ?? null),
            thumbnailUrl: self::text($input['thumbnailUrl'] ?? null),
            categoryId: self::text($input['categoryId'] ?? null),
            maxCapacity: $capacity,
            allowWaitlist: $allowWaitlist,
            minAttendancePercentage: $attendance,
            mandatorySessionsOnly: filter_var($input['mandatorySessionsOnly'] ?? false, FILTER_VALIDATE_BOOL),
            issuesCertificate: filter_var($input['issuesCertificate'] ?? false, FILTER_VALIDATE_BOOL),
            reminderOffsetsMinutes: $offsets,
            reminderChannels: $channels,
            qrGracePeriodMinutes: (int) ($input['qrGracePeriodMinutes'] ?? 15),
            qrValidBeforeStartMinutes: (int) ($input['qrValidBeforeStartMinutes'] ?? 30),
            minVirtualDurationMinutes: (int) ($input['minVirtualDurationMinutes'] ?? 30),
        );
    }

    /**
     * Whether someone may still take a place.
     *
     * The capacity check needs a live count, so it is passed in rather than
     * guessed — the same split as `Course::isOpenForSelfEnrollment()`.
     *
     * @return array{allowed: bool, waitlist: bool, reason: ?string}
     */
    public function admits(int $currentlyEnrolled): array
    {
        if (!$this->status->isOpenToLearners()) {
            return ['allowed' => false, 'waitlist' => false, 'reason' => 'This programme is not open.'];
        }

        if ($this->maxCapacity === null || $currentlyEnrolled < $this->maxCapacity) {
            return ['allowed' => true, 'waitlist' => false, 'reason' => null];
        }

        if ($this->allowWaitlist) {
            return ['allowed' => true, 'waitlist' => true, 'reason' => null];
        }

        return ['allowed' => false, 'waitlist' => false, 'reason' => 'This programme is full.'];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function toArray(array $extra = []): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'summary'     => $this->summary,
            'thumbnailUrl' => $this->thumbnailUrl,
            'categoryId'  => $this->categoryId,
            'delivery'    => $this->delivery->value,
            'deliveryLabel' => $this->delivery->label(),
            'status'      => $this->status->value,
            'statusLabel' => $this->status->label(),
            'maxCapacity' => $this->maxCapacity,
            'allowWaitlist' => $this->allowWaitlist,
            'minAttendancePercentage' => $this->minAttendancePercentage,
            'mandatorySessionsOnly'   => $this->mandatorySessionsOnly,
            'issuesCertificate'       => $this->issuesCertificate,
            'reminderOffsetsMinutes'  => $this->reminderOffsetsMinutes,
            'reminderChannels'        => $this->reminderChannels,
            'qrGracePeriodMinutes'    => $this->qrGracePeriodMinutes,
            'qrValidBeforeStartMinutes' => $this->qrValidBeforeStartMinutes,
            'minVirtualDurationMinutes' => $this->minVirtualDurationMinutes,
            'sessionCount'  => $this->sessionCount,
            'enrolledCount' => $this->enrolledCount,
            'publishedAt'   => $this->publishedAt?->format(\DateTimeInterface::ATOM),
            'createdAt'     => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'     => $this->updatedAt?->format(\DateTimeInterface::ATOM),
            ...$extra,
        ];
    }

    /**
     * @param array<string, string> $violations
     *
     * @return list<int>
     */
    private static function offsets(mixed $value, array &$violations): array
    {
        if ($value === null) {
            return [1440, 60];
        }

        if (!is_array($value)) {
            $violations['reminderOffsetsMinutes'] = 'Send a list of minutes before the session.';

            return [];
        }

        $offsets = [];

        foreach ($value as $raw) {
            if (!is_numeric($raw) || (int) $raw < 0) {
                $violations['reminderOffsetsMinutes'] = 'Each reminder is a number of minutes before the start.';

                return [];
            }

            $offsets[] = (int) $raw;
        }

        $offsets = array_values(array_unique($offsets));
        rsort($offsets);

        return $offsets;
    }

    /**
     * Unwraps the legacy triple-encoded channel list.
     *
     * Live row 1 holds `"\"[\\\"email\\\", \\\"in_app\\\"]\""` — a JSON string
     * containing a JSON string containing a JSON array. Decoding once leaves a
     * string; this keeps unwrapping until it reaches a list or gives up.
     *
     * @param array<string, string> $violations
     *
     * @return list<string>
     */
    private static function channels(mixed $value, array &$violations): array
    {
        for ($depth = 0; is_string($value) && $depth < 5; $depth++) {
            $decoded = json_decode($value, true);

            if ($decoded === null) {
                break;
            }

            $value = $decoded;
        }

        if ($value === null) {
            return ['email'];
        }

        if (!is_array($value)) {
            $violations['reminderChannels'] = 'Send a list of channels.';

            return [];
        }

        $channels = [];

        foreach ($value as $raw) {
            $channel = is_string($raw) ? strtolower(trim($raw)) : '';

            if (!in_array($channel, self::CHANNELS, true)) {
                $violations['reminderChannels'] = 'Choose from: ' . implode(', ', self::CHANNELS) . '.';

                return [];
            }

            $channels[] = $channel;
        }

        return array_values(array_unique($channels));
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : $value;
    }
}
