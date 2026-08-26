<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * One occurrence of a training programme.
 *
 * The meeting fields are written by the scheduler, never by a client: they are
 * what the provider returned. In the legacy data they are NULL on every row,
 * including the one marked virtual.
 */
final readonly class TrainingSession
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $trainingId,
        public string $title,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public string $timezone = 'UTC',
        public TrainingDelivery $delivery = TrainingDelivery::Virtual,
        public SessionStatus $status = SessionStatus::Scheduled,
        public int $sequence = 1,
        public ?string $description = null,
        public ?string $instructorId = null,
        public ?string $venueName = null,
        public ?string $venueAddress = null,
        public ?string $venueRoom = null,
        public ?int $venueCapacity = null,
        public ?string $venueMapUrl = null,
        public ?MeetingProviderId $platform = null,
        public ?string $integrationId = null,
        public ?string $externalMeetingId = null,
        public ?string $joinUrl = null,
        public ?string $hostUrl = null,
        public ?string $meetingPassword = null,
        public ?\DateTimeImmutable $meetingScheduledAt = null,
        public ?string $meetingError = null,
        public bool $isMandatory = true,
        public bool $allowLateJoin = true,
        public int $lateThresholdMinutes = 15,
        public ?string $qrToken = null,
        public ?\DateTimeImmutable $qrGeneratedAt = null,
        public ?\DateTimeImmutable $qrExpiresAt = null,
        public bool $attendanceLocked = false,
        public ?\DateTimeImmutable $actualStartedAt = null,
        public ?\DateTimeImmutable $actualEndedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(
        string $id,
        string $tenantId,
        string $trainingId,
        TrainingDelivery $trainingDelivery,
        array $input,
    ): self {
        $violations = [];

        $title = trim((string) ($input['title'] ?? ''));

        if ($title === '') {
            $violations['title'] = 'Enter a session title.';
        } elseif (mb_strlen($title) > 255) {
            $violations['title'] = 'Title must be 255 characters or fewer.';
        }

        $startsAt = self::parseInstant($input['startsAt'] ?? null);
        $endsAt = self::parseInstant($input['endsAt'] ?? null);

        if ($startsAt === null) {
            $violations['startsAt'] = 'When does it start?';
        }

        if ($endsAt === null) {
            $violations['endsAt'] = 'When does it end?';
        }

        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            $violations['endsAt'] = 'A session cannot end before it starts.';
        }

        $timezone = trim((string) ($input['timezone'] ?? 'UTC')) ?: 'UTC';

        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            // A bad zone name would be accepted silently and then produce the
            // wrong local time in every reminder and calendar invitation.
            $violations['timezone'] = sprintf('"%s" is not a known timezone.', $timezone);
        }

        $delivery = isset($input['delivery']) && trim((string) $input['delivery']) !== ''
            ? TrainingDelivery::fromLegacy((string) $input['delivery'])
            : $trainingDelivery;

        // A physical programme cannot hold an online session: nobody would be
        // told which platform, because the programme has none.
        if ($trainingDelivery === TrainingDelivery::Physical && $delivery !== TrainingDelivery::Physical) {
            $violations['delivery'] = 'This is an in-person programme, so its sessions are in person.';
        }

        if ($trainingDelivery === TrainingDelivery::Virtual && $delivery !== TrainingDelivery::Virtual) {
            $violations['delivery'] = 'This is an online programme, so its sessions are online.';
        }

        $late = (int) ($input['lateThresholdMinutes'] ?? 15);

        if ($late < 0) {
            $violations['lateThresholdMinutes'] = 'A late threshold cannot be negative.';
        }

        $sequence = (int) ($input['sequence'] ?? 1);

        if ($sequence < 1) {
            $violations['sequence'] = 'Sessions are numbered from one.';
        }

        if ($delivery->needsVenue() && self::text($input['venueName'] ?? null) === null) {
            // Not fatal for a hybrid session being drafted, but a physical one
            // with no venue is a session nobody can find.
            if ($delivery === TrainingDelivery::Physical) {
                $violations['venueName'] = 'Where is it being held?';
            }
        }

        if ($violations !== [] || $startsAt === null || $endsAt === null) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            trainingId: $trainingId,
            title: $title,
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $timezone,
            delivery: $delivery,
            sequence: $sequence,
            description: self::text($input['description'] ?? null),
            instructorId: self::text($input['instructorId'] ?? null),
            venueName: self::text($input['venueName'] ?? null),
            venueAddress: self::text($input['venueAddress'] ?? null),
            venueRoom: self::text($input['venueRoom'] ?? null),
            venueCapacity: isset($input['venueCapacity']) && is_numeric($input['venueCapacity'])
                ? max(0, (int) $input['venueCapacity'])
                : null,
            venueMapUrl: self::text($input['venueMapUrl'] ?? null),
            isMandatory: filter_var($input['isMandatory'] ?? true, FILTER_VALIDATE_BOOL),
            allowLateJoin: filter_var($input['allowLateJoin'] ?? true, FILTER_VALIDATE_BOOL),
            lateThresholdMinutes: $late,
        );
    }

    public function durationMinutes(): int
    {
        return (int) max(1, round(($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp()) / 60));
    }

    /**
     * Whether a meeting should exist and does not.
     *
     * This is the question nothing in the legacy code asked. Session 2 in the
     * live data answers true and always has.
     */
    public function needsMeeting(): bool
    {
        return $this->delivery->needsMeeting()
            && $this->status->needsMeeting()
            && $this->externalMeetingId === null;
    }

    public function hasMeeting(): bool
    {
        return $this->externalMeetingId !== null && $this->joinUrl !== null;
    }

    /**
     * Whether someone arriving now is late.
     */
    public function isLate(\DateTimeImmutable $now): bool
    {
        return $now > $this->startsAt->modify(sprintf('+%d minutes', $this->lateThresholdMinutes));
    }

    /**
     * Whether a QR code presented now should be accepted.
     *
     * Both ends matter: too early and people scan on their way past the day
     * before, too late and the code is a permanent key to the register.
     */
    public function qrIsValid(string $token, \DateTimeImmutable $now): bool
    {
        if ($this->qrToken === null || !hash_equals($this->qrToken, $token)) {
            return false;
        }

        return $this->qrExpiresAt !== null && $now <= $this->qrExpiresAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeHostUrl = false): array
    {
        $payload = [
            'id'          => $this->id,
            'trainingId'  => $this->trainingId,
            'title'       => $this->title,
            'description' => $this->description,
            'sequence'    => $this->sequence,
            'delivery'    => $this->delivery->value,
            'deliveryLabel' => $this->delivery->label(),
            'status'      => $this->status->value,
            'startsAt'    => $this->startsAt->format(\DateTimeInterface::ATOM),
            'endsAt'      => $this->endsAt->format(\DateTimeInterface::ATOM),
            'timezone'    => $this->timezone,
            'durationMinutes' => $this->durationMinutes(),
            'instructorId'    => $this->instructorId,
            'venue' => $this->delivery->needsVenue() ? [
                'name'     => $this->venueName,
                'address'  => $this->venueAddress,
                'room'     => $this->venueRoom,
                'capacity' => $this->venueCapacity,
                'mapUrl'   => $this->venueMapUrl,
            ] : null,
            'meeting' => $this->hasMeeting() ? [
                'platform' => $this->platform?->value,
                'joinUrl'  => $this->joinUrl,
                'password' => $this->meetingPassword,
                'scheduledAt' => $this->meetingScheduledAt?->format(\DateTimeInterface::ATOM),
            ] : null,
            // Surfaced so an organiser can see that scheduling failed rather
            // than discovering an empty join button at the start time.
            'meetingError' => $this->meetingError,
            'isMandatory'  => $this->isMandatory,
            'allowLateJoin' => $this->allowLateJoin,
            'lateThresholdMinutes' => $this->lateThresholdMinutes,
            'attendanceLocked' => $this->attendanceLocked,
            'actualStartedAt'  => $this->actualStartedAt?->format(\DateTimeInterface::ATOM),
            'actualEndedAt'    => $this->actualEndedAt?->format(\DateTimeInterface::ATOM),
        ];

        // The host URL starts the meeting; a learner holding it can start the
        // session, mute people and admit strangers.
        if ($includeHostUrl && $this->hostUrl !== null) {
            $payload['meeting']['hostUrl'] = $this->hostUrl;
            $payload['meeting']['externalId'] = $this->externalMeetingId;
        }

        return $payload;
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : $value;
    }

    private static function parseInstant(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
    }
}
