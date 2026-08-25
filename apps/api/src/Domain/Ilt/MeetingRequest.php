<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * A provider-neutral description of the session we want scheduled.
 *
 * This is the whole point of the ILT rewrite: callers describe the session
 * once, and each adapter translates it into that vendor's payload. Nothing
 * upstream of an adapter may contain a Zoom field name or a Graph API shape.
 */
final readonly class MeetingRequest
{
    /**
     * @param list<string> $attendeeEmails
     */
    public function __construct(
        public string $topic,
        public \DateTimeImmutable $startsAt,
        public int $durationMinutes,
        public string $timezone = 'UTC',
        public ?string $agenda = null,
        public ?string $hostEmail = null,
        public array $attendeeEmails = [],
        public bool $recordAutomatically = false,
        public bool $waitingRoom = true,
        public bool $joinBeforeHost = false,
        /**
         * Our own ILT session id. Adapters pass it to the provider where a
         * client-reference field exists, which is what makes a retried create
         * recoverable instead of producing a duplicate meeting.
         */
        public ?string $externalRef = null,
    ) {
        if (trim($topic) === '') {
            throw new \InvalidArgumentException('A meeting must have a topic.');
        }

        if ($durationMinutes < 1) {
            throw new \InvalidArgumentException('Meeting duration must be at least one minute.');
        }
    }

    public function endsAt(): \DateTimeImmutable
    {
        return $this->startsAt->add(new \DateInterval('PT' . $this->durationMinutes . 'M'));
    }

    /** Provider APIs universally accept RFC 3339 / ISO 8601 in UTC. */
    public function startsAtIso(): string
    {
        return $this->startsAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public function endsAtIso(): string
    {
        return $this->endsAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
