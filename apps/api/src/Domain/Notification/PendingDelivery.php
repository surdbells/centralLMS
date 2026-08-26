<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * A claimed delivery, with everything a transport needs to send it.
 *
 * Assembled by one join rather than by handing the dispatcher a delivery and
 * letting it fetch the notification: a worker taking fifty rows would
 * otherwise make a hundred and one queries to send fifty messages.
 */
final readonly class PendingDelivery
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $deliveryId,
        public string $tenantId,
        public string $notificationId,
        public string $userId,
        public NotificationChannel $channel,
        public string $address,
        public NotificationType $type,
        public string $title,
        public string $body,
        public array $data,
        public NotificationPriority $priority,
        public int $attempts,
        public int $maxAttempts,
    ) {
    }

    public function isLastAttempt(): bool
    {
        return $this->attempts + 1 >= $this->maxAttempts;
    }

    /**
     * Push tokens travel as one comma-separated address. See Recipient.
     *
     * @return list<string>
     */
    public function addresses(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $this->address)),
            static fn (string $a): bool => $a !== '',
        ));
    }
}
