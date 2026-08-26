<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * How a notification reaches someone.
 *
 * The legacy declared these three times with three different memberships —
 * `('email','in_app','sms','whatsapp')` on the ILT queue, `('in_app','email',
 * 'push')` on the messaging queue, and `('never','instant','hourly','daily',
 * 'weekly')` for the digest — so "which channels exist" had no answer.
 */
enum NotificationChannel: string
{
    /** The bell. Always available; there is nothing to configure and nothing to fail. */
    case InApp = 'in_app';

    case Email = 'email';
    case Sms   = 'sms';
    case Push  = 'push';

    /**
     * Whether the channel needs an address on the recipient.
     *
     * In-app does not: the notification row itself is the delivery.
     */
    public function needsAddress(): bool
    {
        return $this !== self::InApp;
    }

    /**
     * Whether a message on this channel costs money or interrupts.
     *
     * Quiet hours apply to these and not to the rest: holding back an in-app
     * notification until morning would mean the bell lies about what has
     * happened, while an SMS at 3am wakes someone up.
     */
    public function isIntrusive(): bool
    {
        return $this === self::Sms || $this === self::Push;
    }

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'In-app',
            self::Email => 'Email',
            self::Sms   => 'SMS',
            self::Push  => 'Push',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * @param  list<string> $values
     * @return list<self>
     */
    public static function fromList(array $values): array
    {
        $channels = [];

        foreach ($values as $value) {
            $channel = self::tryFrom(strtolower(trim($value)));

            if ($channel !== null && !in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }
}
