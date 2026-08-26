<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * Everything routing needs to know about one person.
 *
 * A snapshot, deliberately. Once a delivery row is written it holds the
 * address as it was at that moment, so changing an email address does not
 * rewrite where last month's messages went.
 */
final readonly class Recipient
{
    /**
     * @param list<NotificationPreference> $preferences
     * @param list<string>                 $pushTokens
     */
    public function __construct(
        public string $userId,
        public ?string $email = null,
        public ?string $phone = null,
        public array $pushTokens = [],
        public array $preferences = [],
        public ?QuietHours $quietHours = null,
        /** IANA name. Quiet hours are meaningless without one. */
        public string $timezone = 'UTC',
        public string $name = '',
    ) {
    }

    public function addressFor(NotificationChannel $channel): ?string
    {
        $address = match ($channel) {
            NotificationChannel::InApp => null,
            NotificationChannel::Email => $this->email,
            NotificationChannel::Sms   => $this->phone,
            // One row per person per channel, so the tokens travel together
            // and the transport fans out. A phone that has been reinstalled
            // leaves a dead token behind, and one dead token must not mark
            // the whole delivery failed.
            NotificationChannel::Push  => $this->pushTokens === []
                ? null
                : implode(',', $this->pushTokens),
        };

        $address = $address === null ? null : trim($address);

        return $address === '' ? null : $address;
    }

    public function canReceiveOn(NotificationChannel $channel): bool
    {
        return !$channel->needsAddress() || $this->addressFor($channel) !== null;
    }

    /**
     * Whether this person has turned the type off on the channel.
     *
     * A type-specific row beats the channel-wide one, so someone can silence
     * email in general and still keep session reminders.
     */
    public function allows(NotificationType $type, NotificationChannel $channel): bool
    {
        $channelWide = null;

        foreach ($this->preferences as $preference) {
            if ($preference->channel !== $channel) {
                continue;
            }

            if ($preference->type === $type) {
                return $preference->enabled;
            }

            if ($preference->type === null) {
                $channelWide = $preference->enabled;
            }
        }

        return $channelWide ?? true;
    }

    /**
     * The person's own wall clock, for quiet hours.
     *
     * Falls back to the given instant unchanged if the stored timezone is not
     * a name PHP knows — a bad value should not stop the message.
     */
    public function localTime(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        try {
            return $instant->setTimezone(new \DateTimeZone($this->timezone));
        } catch (\Exception) {
            return $instant;
        }
    }
}
