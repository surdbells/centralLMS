<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * Storage for the inbox, the delivery attempts, the preferences and the
 * schedules.
 */
interface NotificationRepository
{
    // --- the inbox --------------------------------------------------------

    /**
     * One person's notifications, newest first.
     *
     * Expired and soft-deleted rows are excluded here rather than filtered by
     * the caller — a bell that shows last week's reminder because a caller
     * forgot a clause is the bug this prevents.
     *
     * @param  array{unreadOnly?: bool, type?: NotificationType} $filters
     * @return array{items: list<Notification>, total: int}
     */
    public function inbox(string $tenantId, string $userId, int $page, int $perPage, array $filters = []): array;

    public function find(string $tenantId, string $id): ?Notification;

    /**
     * With its delivery rows attached, for the admin view.
     */
    public function findWithDeliveries(string $tenantId, string $id): ?Notification;

    public function unreadCount(string $tenantId, string $userId): int;

    /**
     * @return int the number of rows actually changed
     */
    public function markRead(string $tenantId, string $userId, string $id): int;

    /**
     * @return int the number marked
     */
    public function markAllRead(string $tenantId, string $userId): int;

    public function softDelete(string $tenantId, string $userId, string $id): bool;

    // --- raising ----------------------------------------------------------

    /**
     * Writes a notification and its deliveries in one transaction.
     *
     * Both or neither: a notification with no delivery rows is invisible to
     * the dispatcher and would simply never be sent, and a delivery with no
     * notification has nothing to say.
     *
     * @param  list<NotificationDelivery> $deliveries
     */
    public function raise(Notification $notification, array $deliveries): Notification;

    /**
     * Everything routing needs about a set of people, in one query.
     *
     * @param  list<string> $userIds
     * @return array<string, Recipient> keyed by user id
     */
    public function recipients(string $tenantId, array $userIds): array;

    // --- delivery ---------------------------------------------------------

    /**
     * Takes ownership of up to $limit due deliveries.
     *
     * Must be atomic against other workers — two workers claiming the same
     * row means the same email twice, and a duplicate reminder is the most
     * visible possible bug in a notification system.
     *
     * @return list<PendingDelivery>
     */
    public function claimDue(string $workerId, \DateTimeImmutable $now, int $limit = 50): array;

    public function markSent(string $deliveryId, \DateTimeImmutable $at, ?string $providerMessageId): void;

    /**
     * Records a failed attempt, rescheduling it if attempts remain.
     *
     * `$exhaust` is for a permanent rejection — a malformed address, an
     * unconfigured provider. Two more attempts will fail identically, and all
     * they achieve is to delay by seven minutes the moment the row says
     * `failed` and somebody can see what is wrong.
     */
    public function markAttemptFailed(
        string $deliveryId,
        \DateTimeImmutable $at,
        string $error,
        bool $exhaust = false,
    ): void;

    /**
     * Releases deliveries whose worker died mid-send.
     *
     * Without this a worker killed between claiming and sending leaves rows
     * in `sending` forever, and the notifications silently stop.
     *
     * @return int the number released
     */
    public function releaseStale(\DateTimeImmutable $before): int;

    /**
     * @param  array{status?: DeliveryStatus, channel?: NotificationChannel} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function deliveries(string $tenantId, int $page, int $perPage, array $filters = []): array;

    // --- tenant configuration ---------------------------------------------

    /**
     * The billable channels this tenant has bought.
     *
     * In-app and email are not listed: they are always available and cost
     * nothing per message. SMS and push are metered, and defaulting a tenant
     * into per-message billing because a column was missing is the wrong
     * direction to fail in — absent means not entitled.
     *
     * @return list<NotificationChannel>
     */
    public function entitledChannels(string $tenantId): array;

    // --- preferences ------------------------------------------------------

    /**
     * @return list<NotificationPreference>
     */
    public function preferencesFor(string $tenantId, string $userId): array;

    public function savePreference(NotificationPreference $preference): NotificationPreference;

    /**
     * Both ends together, or both cleared.
     */
    public function saveQuietHours(string $tenantId, string $userId, ?QuietHours $quietHours): void;

    // --- schedules --------------------------------------------------------

    /**
     * Makes the given schedules the complete set for the subject.
     *
     * Called on every save of a session, so it must be idempotent: schedules
     * that already exist are left alone rather than recreated, or a session
     * edited twice would send its reminders twice.
     *
     * @param  list<NotificationSchedule> $schedules
     * @return list<NotificationSchedule>
     */
    public function syncSchedules(
        string $tenantId,
        string $subjectType,
        string $subjectId,
        NotificationType $type,
        array $schedules,
    ): array;

    /**
     * @return list<NotificationSchedule>
     */
    public function schedulesFor(string $tenantId, string $subjectType, string $subjectId): array;

    /**
     * Open schedules whose subject starts soon enough that they may be due.
     *
     * The subject's current start time comes back with each one, because the
     * due time is computed from it and never stored.
     *
     * @return list<DueSchedule>
     */
    public function dueSchedules(\DateTimeImmutable $now, int $limit = 50): array;

    public function markScheduleExpanded(string $scheduleId, \DateTimeImmutable $at, int $recipients): void;

    public function markScheduleSkipped(string $scheduleId, string $reason): void;

    public function markScheduleFailed(string $scheduleId, string $error): void;

    /**
     * @return int the number cancelled
     */
    public function cancelSchedules(string $tenantId, string $subjectType, string $subjectId): int;
}
