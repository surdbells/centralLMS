<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * One attempt to reach one person on one channel.
 *
 * There is exactly one row per notification per channel; a retry updates it
 * rather than adding another, so `attempts` and the row never disagree. That
 * is the opposite of the legacy's `live_training_reminder_log`, which appended
 * and then needed three counters on the parent to say what it already knew.
 */
final readonly class NotificationDelivery
{
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public ?string $notificationId,
        public NotificationChannel $channel,
        public ?string $address = null,
        public DeliveryStatus $status = DeliveryStatus::Pending,
        public int $attempts = 0,
        public int $maxAttempts = 3,
        public ?\DateTimeImmutable $scheduledFor = null,
        public ?\DateTimeImmutable $claimedAt = null,
        public ?string $claimedBy = null,
        public ?\DateTimeImmutable $sentAt = null,
        public ?\DateTimeImmutable $failedAt = null,
        public ?string $error = null,
        public ?string $providerMessageId = null,
    ) {
    }

    /**
     * Whether another attempt is allowed.
     *
     * A skipped delivery is never retried: the reason it was skipped — no
     * mobile number, opted out — does not change by trying again.
     */
    public function canRetry(): bool
    {
        return $this->status !== DeliveryStatus::Skipped
            && $this->status !== DeliveryStatus::Sent
            && $this->attempts < $this->maxAttempts;
    }

    /**
     * How long to wait before the next attempt.
     *
     * Exponential, because the overwhelming cause of a failed send is the
     * provider being briefly unavailable, and hammering it is how a brief
     * outage becomes a rate-limit ban.
     */
    public function retryDelaySeconds(): int
    {
        return 60 * (2 ** max(0, $this->attempts - 1));
    }

    /**
     * Builds the delivery a routing decision calls for.
     *
     * In-app is born `sent`. There is nothing to transmit — the notification
     * row *is* the delivery, and it is on the person's bell the instant it is
     * written. Recording it as `pending` would put a queue entry in the
     * delivery log for something already delivered, and anyone reading that
     * log to answer "did it go out" would draw the wrong conclusion.
     */
    public static function from(
        string $tenantId,
        ?string $notificationId,
        RoutingDecision $decision,
        Recipient $recipient,
        \DateTimeImmutable $now,
    ): self {
        $immediate = $decision->send && $decision->channel === NotificationChannel::InApp;

        return new self(
            id: null,
            tenantId: $tenantId,
            notificationId: $notificationId,
            channel: $decision->channel,
            address: $decision->send ? $recipient->addressFor($decision->channel) : null,
            status: $immediate ? DeliveryStatus::Sent : $decision->status(),
            scheduledFor: $decision->sendAt ?? $now,
            sentAt: $immediate ? $now : null,
            error: $decision->reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'channel'       => $this->channel->value,
            'channelLabel'  => $this->channel->label(),
            // The address is not echoed. A delivery list is an admin screen,
            // and it does not need to hand out the mobile numbers of everyone
            // on a course to see whether the reminders went out.
            'status'        => $this->status->value,
            'attempts'      => $this->attempts,
            'maxAttempts'   => $this->maxAttempts,
            'scheduledFor'  => $this->scheduledFor?->format(\DateTimeInterface::ATOM),
            'sentAt'        => $this->sentAt?->format(\DateTimeInterface::ATOM),
            'failedAt'      => $this->failedAt?->format(\DateTimeInterface::ATOM),
            'error'         => $this->error,
            'canRetry'      => $this->canRetry(),
        ];
    }
}
