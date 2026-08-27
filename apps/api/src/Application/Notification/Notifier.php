<?php

declare(strict_types=1);

namespace Central\Api\Application\Notification;

use Central\Api\Domain\Notification\ChannelRouter;
use Central\Api\Domain\Notification\Notification;
use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationComposer;
use Central\Api\Domain\Notification\NotificationDelivery;
use Central\Api\Domain\Notification\NotificationRepository;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * The one way anything raises a notification.
 *
 * Every other module calls `notify()` and nothing else. That is what keeps the
 * routing rules in one place: the legacy had two queue tables written by two
 * different modules with two different sets of channel names, and no shared
 * notion of a preference, because there was no single door to go through.
 *
 * **Raising never throws.** A course completion that cannot send an email must
 * still be a completed course. Callers are mid-transaction on something that
 * matters more than the message, so failures are logged and swallowed; the
 * one caller that does want to hear about problems — the admin "send an
 * announcement" endpoint — reads the returned count.
 */
final readonly class Notifier
{
    public function __construct(
        private NotificationRepository $repository,
        private NotificationComposer $composer,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Raises one notification per recipient.
     *
     * @param  list<string>                   $userIds
     * @param  array<string, mixed>           $data
     * @param  list<NotificationChannel>|null $channels overrides the type's defaults
     * @return int                            how many people were actually notified
     */
    public function notify(
        string $tenantId,
        array $userIds,
        NotificationType $type,
        array $data = [],
        ?string $subjectId = null,
        ?array $channels = null,
    ): int {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($userIds === []) {
            return 0;
        }

        $missing = $this->composer->missingData($type, $data);

        if ($missing !== []) {
            // Logged loudly rather than sent: a notification reading "You have
            // been assigned the course" is worse than one that never arrives,
            // because the learner cannot tell which course they mean.
            $this->logger->error('Refusing to raise a notification with nothing to say', [
                'type'    => $type->value,
                'missing' => $missing,
                'tenant'  => $tenantId,
            ]);

            return 0;
        }

        $now       = $this->clock->now();
        $notified  = 0;

        try {
            $recipients = $this->repository->recipients(
                $tenantId,
                $userIds,
                $type->reachesPendingAccounts(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Could not load notification recipients', [
                'type'  => $type->value,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $router = new ChannelRouter($this->tenantChannels($tenantId));

        foreach ($userIds as $userId) {
            $recipient = $recipients[$userId] ?? null;

            if ($recipient === null) {
                // Not an error worth shouting about: an audience assembled a
                // moment ago can contain someone deleted since.
                $this->logger->info('Skipping a notification for an unknown user', [
                    'user' => $userId,
                    'type' => $type->value,
                ]);

                continue;
            }

            try {
                $notification = Notification::raise(
                    $tenantId,
                    $userId,
                    $type,
                    $data,
                    $this->composer,
                    $now,
                    $subjectId,
                );

                $deliveries = array_map(
                    static fn ($decision): NotificationDelivery => NotificationDelivery::from(
                        $tenantId,
                        null,
                        $decision,
                        $recipient,
                        $now,
                    ),
                    $router->route($type, $recipient, $now, $channels),
                );

                $this->repository->raise($notification, $deliveries);
                $notified++;
            } catch (\Throwable $e) {
                // One bad recipient must not lose the rest of the batch.
                $this->logger->error('Could not raise a notification', [
                    'user'  => $userId,
                    'type'  => $type->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $notified;
    }

    /**
     * Convenience for the common case of telling one person.
     *
     * @param array<string, mixed> $data
     */
    public function notifyOne(
        string $tenantId,
        string $userId,
        NotificationType $type,
        array $data = [],
        ?string $subjectId = null,
    ): bool {
        return $this->notify($tenantId, [$userId], $type, $data, $subjectId) === 1;
    }

    /**
     * Which channels the tenant is allowed to use.
     *
     * In-app and email are always available. SMS and push are billable, so
     * they stay off until the tenant's entitlements say otherwise — the same
     * mechanism proctoring uses.
     *
     * @return list<NotificationChannel>
     */
    private function tenantChannels(string $tenantId): array
    {
        $channels = [NotificationChannel::InApp, NotificationChannel::Email];

        foreach ($this->repository->entitledChannels($tenantId) as $channel) {
            if (!in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }
}
