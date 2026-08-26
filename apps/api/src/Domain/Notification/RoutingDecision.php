<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * What the router decided about one channel, and why.
 *
 * A skip carries its reason all the way to the delivery row, so the admin
 * screen can say "no mobile number on record" rather than showing a blank
 * where an SMS should have been.
 */
final readonly class RoutingDecision
{
    private function __construct(
        public NotificationChannel $channel,
        public bool $send,
        public ?\DateTimeImmutable $sendAt,
        public ?string $reason,
    ) {
    }

    public static function send(NotificationChannel $channel, \DateTimeImmutable $at): self
    {
        return new self($channel, true, $at, null);
    }

    public static function skip(NotificationChannel $channel, string $reason): self
    {
        return new self($channel, false, null, $reason);
    }

    /**
     * Whether it was held back rather than sent immediately.
     */
    public function isDeferred(\DateTimeImmutable $now): bool
    {
        return $this->send && $this->sendAt !== null && $this->sendAt > $now;
    }

    public function status(): DeliveryStatus
    {
        return $this->send ? DeliveryStatus::Pending : DeliveryStatus::Skipped;
    }
}
