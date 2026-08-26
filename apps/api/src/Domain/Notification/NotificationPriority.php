<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * How loudly a notification asks for attention.
 *
 * Not decoration: it decides ordering on the bell and whether quiet hours may
 * hold a message back. Urgent is the one exception that gets through the
 * night, which is the whole reason the level exists.
 */
enum NotificationPriority: string
{
    case Low    = 'low';
    case Normal = 'normal';
    case High   = 'high';
    case Urgent = 'urgent';

    /**
     * Whether quiet hours may delay it.
     *
     * Only urgent overrides. A session cancelled at 06:00 for a 09:00 start
     * has to arrive before the learner sets off, and holding it until the
     * quiet window closes is exactly the failure the window was meant to
     * prevent somewhere else.
     */
    public function ignoresQuietHours(): bool
    {
        return $this === self::Urgent;
    }

    /**
     * Sort weight, highest first.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 3,
            self::High   => 2,
            self::Normal => 1,
            self::Low    => 0,
        };
    }

    public static function fromLegacy(?string $value): self
    {
        return match (strtolower(trim((string) $value))) {
            'important' => self::High,
            'urgent'    => self::Urgent,
            'low'       => self::Low,
            default     => self::Normal,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
