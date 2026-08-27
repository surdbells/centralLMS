<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Notification\DeliveryStatus;
use Central\Api\Domain\Notification\DueSchedule;
use Central\Api\Domain\Notification\Notification;
use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationDelivery;
use Central\Api\Domain\Notification\NotificationPreference;
use Central\Api\Domain\Notification\NotificationPriority;
use Central\Api\Domain\Notification\NotificationRepository;
use Central\Api\Domain\Notification\NotificationSchedule;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Domain\Notification\PendingDelivery;
use Central\Api\Domain\Notification\QuietHours;
use Central\Api\Domain\Notification\Recipient;
use Central\Api\Domain\Notification\ScheduleStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DbalNotificationRepository implements NotificationRepository
{
    private const COLUMNS = 'n.id, n.tenant_id, n.user_id, n.type, n.title, n.body,
                             n.subject_type, n.subject_id, n.data, n.priority,
                             n.read_at, n.expires_at, n.created_at';

    /**
     * Entitlement keys for the two metered channels.
     */
    private const CHANNEL_ENTITLEMENTS = [
        'notifications.sms'  => NotificationChannel::Sms,
        'notifications.push' => NotificationChannel::Push,
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    // --- the inbox --------------------------------------------------------

    public function inbox(string $tenantId, string $userId, int $page, int $perPage, array $filters = []): array
    {
        // Expiry is applied here rather than left to the caller. A reminder
        // for a session that finished yesterday reappearing on the bell
        // because one query forgot a clause is exactly the bug this prevents.
        $where  = ['n.tenant_id = :tenant', 'n.user_id = :user', 'n.deleted_at IS NULL',
                   '(n.expires_at IS NULL OR n.expires_at > NOW())'];
        $params = ['tenant' => $tenantId, 'user' => $userId];

        if (($filters['unreadOnly'] ?? false) === true) {
            $where[] = 'n.read_at IS NULL';
        }

        if (isset($filters['type']) && $filters['type'] instanceof NotificationType) {
            $where[]        = 'n.type = :type';
            $params['type'] = $filters['type']->value;
        }

        $clause = implode(' AND ', $where);

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM notifications n WHERE {$clause}",
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM notifications n WHERE %s ORDER BY n.created_at DESC, n.id DESC LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                $clause,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $row): Notification => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $id): ?Notification
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT %s FROM notifications n WHERE n.id = :id AND n.tenant_id = :tenant AND n.deleted_at IS NULL', self::COLUMNS),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findWithDeliveries(string $tenantId, string $id): ?Notification
    {
        $notification = $this->find($tenantId, $id);

        if ($notification === null) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM notification_deliveries WHERE notification_id = :id AND tenant_id = :tenant ORDER BY channel',
            ['id' => $id, 'tenant' => $tenantId],
        );

        return new Notification(
            id: $notification->id,
            tenantId: $notification->tenantId,
            userId: $notification->userId,
            type: $notification->type,
            title: $notification->title,
            body: $notification->body,
            subjectType: $notification->subjectType,
            subjectId: $notification->subjectId,
            data: $notification->data,
            priority: $notification->priority,
            readAt: $notification->readAt,
            expiresAt: $notification->expiresAt,
            createdAt: $notification->createdAt,
            deliveries: array_map(fn (array $row): NotificationDelivery => $this->hydrateDelivery($row), $rows),
        );
    }

    public function unreadCount(string $tenantId, string $userId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM notifications
             WHERE tenant_id = :tenant AND user_id = :user
               AND read_at IS NULL AND deleted_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())',
            ['tenant' => $tenantId, 'user' => $userId],
        );
    }

    public function markRead(string $tenantId, string $userId, string $id): int
    {
        // The user id is in the WHERE clause, not checked beforehand: without
        // it, one person could mark another's notifications read, and a
        // check-then-update leaves a window where they can.
        return (int) $this->connection->executeStatement(
            'UPDATE notifications SET read_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND user_id = :user
               AND read_at IS NULL AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId, 'user' => $userId],
        );
    }

    public function markAllRead(string $tenantId, string $userId): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE notifications SET read_at = NOW()
             WHERE tenant_id = :tenant AND user_id = :user
               AND read_at IS NULL AND deleted_at IS NULL',
            ['tenant' => $tenantId, 'user' => $userId],
        );
    }

    public function softDelete(string $tenantId, string $userId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE notifications SET deleted_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND user_id = :user AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId, 'user' => $userId],
        ) > 0;
    }

    // --- raising ----------------------------------------------------------

    public function raise(Notification $notification, array $deliveries): Notification
    {
        $id = $this->connection->transactional(
            function (Connection $tx) use ($notification, $deliveries): string {
                $id = (string) $tx->fetchOne(
                    'INSERT INTO notifications
                        (tenant_id, user_id, type, title, body, subject_type, subject_id,
                         data, priority, expires_at, created_at)
                     VALUES (:tenant, :user, :type, :title, :body, :subjectType, :subjectId,
                             :data::jsonb, :priority, :expiresAt, COALESCE(:createdAt, NOW()))
                     RETURNING id',
                    [
                        'tenant'      => $notification->tenantId,
                        'user'        => $notification->userId,
                        'type'        => $notification->type->value,
                        'title'       => $notification->title,
                        'body'        => $notification->body,
                        'subjectType' => $notification->subjectType,
                        'subjectId'   => $notification->subjectId,
                        // JSON_FORCE_OBJECT: an empty PHP array encodes as []
                        // and the column insists on an object.
                        'data'        => json_encode($notification->data, JSON_FORCE_OBJECT),
                        'priority'    => $notification->priority->value,
                        'expiresAt'   => $notification->expiresAt === null ? null : $this->instant($notification->expiresAt),
                        'createdAt'   => $notification->createdAt === null ? null : $this->instant($notification->createdAt),
                    ],
                );

                foreach ($deliveries as $delivery) {
                    $tx->executeStatement(
                        'INSERT INTO notification_deliveries
                            (tenant_id, notification_id, channel, address, status,
                             max_attempts, scheduled_for, sent_at, error)
                         VALUES (:tenant, :notification, :channel, :address, :status,
                                 :maxAttempts, COALESCE(:scheduledFor, NOW()), :sentAt, :error)',
                        [
                            'tenant'       => $delivery->tenantId,
                            'notification' => $id,
                            'channel'      => $delivery->channel->value,
                            'address'      => $delivery->address,
                            'status'       => $delivery->status->value,
                            'maxAttempts'  => $delivery->maxAttempts,
                            'scheduledFor' => $delivery->scheduledFor === null ? null : $this->instant($delivery->scheduledFor),
                            'sentAt'       => $delivery->sentAt === null ? null : $this->instant($delivery->sentAt),
                            'error'        => $delivery->error,
                        ],
                    );
                }

                return $id;
            },
        );

        return $this->find($notification->tenantId, $id)
            ?? throw new \RuntimeException('The notification vanished immediately after being written.');
    }

    public function recipients(
        string $tenantId,
        array $userIds,
        bool $includePending = false,
    ): array {
        if ($userIds === []) {
            return [];
        }

        // `invited` and nothing else when pending accounts are wanted.
        // Suspended and locked accounts stay out either way: somebody an
        // administrator has shut out should not go on receiving email.
        $statuses = $includePending ? ['active', 'invited'] : ['active'];

        $rows = $this->connection->fetchAllAssociative(
            "SELECT u.id, u.email, u.phone, u.locale, u.first_name, u.last_name,
                    u.quiet_hours_start, u.quiet_hours_end
             FROM users u
             WHERE u.tenant_id = :tenant AND u.id IN (:ids) AND u.deleted_at IS NULL
               AND u.status IN (:statuses)",
            ['tenant' => $tenantId, 'ids' => $userIds, 'statuses' => $statuses],
            ['ids' => ArrayParameterType::STRING, 'statuses' => ArrayParameterType::STRING],
        );

        if ($rows === []) {
            return [];
        }

        $preferences = $this->preferencesByUser($tenantId, array_column($rows, 'id'));

        $recipients = [];

        foreach ($rows as $row) {
            $id = (string) $row['id'];

            $recipients[$id] = new Recipient(
                userId: $id,
                email: $this->text($row['email'] ?? null),
                phone: $this->text($row['phone'] ?? null),
                // Nothing stores device tokens yet — there is no mobile app
                // registering them. The push transport is wired and tested;
                // the column it will read is the one thing still to add.
                pushTokens: [],
                preferences: $preferences[$id] ?? [],
                quietHours: QuietHours::tryBetween(
                    $this->text($row['quiet_hours_start'] ?? null),
                    $this->text($row['quiet_hours_end'] ?? null),
                ),
                timezone: $this->timezoneFor($tenantId),
                name: trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? ''))),
            );
        }

        return $recipients;
    }

    // --- delivery ---------------------------------------------------------

    public function claimDue(string $workerId, \DateTimeImmutable $now, int $limit = 50): array
    {
        // One statement, and FOR UPDATE SKIP LOCKED is what makes it safe.
        // Two workers running the same query take disjoint sets rather than
        // blocking on each other or — far worse — both sending the same
        // email. The compose file restarts the worker every five minutes and
        // the old and new processes overlap, so this is the ordinary case.
        $rows = $this->connection->fetchAllAssociative(
            "UPDATE notification_deliveries d
             SET status = 'sending', claimed_at = :now, claimed_by = :worker,
                 attempts = d.attempts + 1, updated_at = :now
             FROM (
                 SELECT id FROM notification_deliveries
                 WHERE status = 'pending' AND claimed_at IS NULL AND scheduled_for <= :now
                 ORDER BY scheduled_for
                 LIMIT :limit
                 FOR UPDATE SKIP LOCKED
             ) AS due
             WHERE d.id = due.id
             RETURNING d.id AS delivery_id, d.tenant_id, d.notification_id, d.channel,
                       d.address, d.attempts, d.max_attempts",
            ['now' => $this->instant($now), 'worker' => $workerId, 'limit' => $limit],
        );

        if ($rows === []) {
            return [];
        }

        $notifications = $this->connection->fetchAllAssociativeIndexed(
            'SELECT id, user_id, type, title, body, data, priority
             FROM notifications WHERE id IN (:ids)',
            ['ids' => array_column($rows, 'notification_id')],
            ['ids' => ArrayParameterType::STRING],
        );

        $pending = [];

        foreach ($rows as $row) {
            $notification = $notifications[(string) $row['notification_id']] ?? null;

            if ($notification === null) {
                continue;
            }

            $pending[] = new PendingDelivery(
                deliveryId: (string) $row['delivery_id'],
                tenantId: (string) $row['tenant_id'],
                notificationId: (string) $row['notification_id'],
                userId: (string) $notification['user_id'],
                channel: NotificationChannel::from((string) $row['channel']),
                address: (string) ($row['address'] ?? ''),
                type: NotificationType::from((string) $notification['type']),
                title: (string) $notification['title'],
                body: (string) $notification['body'],
                data: $this->json($notification['data'] ?? null),
                priority: NotificationPriority::from((string) $notification['priority']),
                attempts: (int) $row['attempts'],
                maxAttempts: (int) $row['max_attempts'],
            );
        }

        return $pending;
    }

    public function markSent(string $deliveryId, \DateTimeImmutable $at, ?string $providerMessageId): void
    {
        $this->connection->executeStatement(
            "UPDATE notification_deliveries
             SET status = 'sent', sent_at = :at, claimed_at = NULL, claimed_by = NULL,
                 error = NULL, failed_at = NULL, provider_message_id = :messageId, updated_at = :at
             WHERE id = :id",
            [
                'id'        => $deliveryId,
                'at'        => $this->instant($at),
                'messageId' => $providerMessageId,
            ],
        );
    }

    public function markAttemptFailed(
        string $deliveryId,
        \DateTimeImmutable $at,
        string $error,
        bool $exhaust = false,
    ): void {
        // Whether this was the last attempt is decided in SQL from the row's
        // own counters. Reading them, deciding in PHP and writing back would
        // race with the retry that another worker has already picked up.
        //
        // The backoff doubles: 60s, 120s, 240s. A provider that is briefly
        // down recovers on its own, and hammering it is how a blip becomes a
        // rate-limit ban.
        $this->connection->executeStatement(
            "UPDATE notification_deliveries
             SET status = CASE
                     WHEN :exhaust OR attempts >= max_attempts THEN 'failed'
                     ELSE 'pending'
                 END,
                 attempts = CASE WHEN :exhaust THEN max_attempts ELSE attempts END,
                 failed_at = CASE
                     WHEN :exhaust OR attempts >= max_attempts THEN :at::timestamptz
                     ELSE NULL
                 END,
                 scheduled_for = CASE
                     WHEN :exhaust OR attempts >= max_attempts THEN scheduled_for
                     ELSE :at::timestamptz + (interval '60 seconds' * POWER(2, GREATEST(0, attempts - 1)))
                 END,
                 error = :error,
                 claimed_at = NULL,
                 claimed_by = NULL,
                 updated_at = :at
             WHERE id = :id",
            [
                'id'      => $deliveryId,
                'at'      => $this->instant($at),
                'error'   => $error,
                'exhaust' => $exhaust,
            ],
            ['exhaust' => \Doctrine\DBAL\ParameterType::BOOLEAN],
        );
    }

    public function releaseStale(\DateTimeImmutable $before): int
    {
        // Back to pending, not to failed: the worker died, which says nothing
        // about whether the message would have gone. Attempts is not
        // incremented again — claiming already did that — so a repeatedly
        // crashing worker still exhausts its budget rather than looping.
        return (int) $this->connection->executeStatement(
            "UPDATE notification_deliveries
             SET status = CASE WHEN attempts >= max_attempts THEN 'failed' ELSE 'pending' END,
                 failed_at = CASE WHEN attempts >= max_attempts THEN NOW() ELSE NULL END,
                 error = CASE
                     WHEN attempts >= max_attempts
                     THEN 'The worker stopped before this could be sent, and no attempts remain.'
                     ELSE error
                 END,
                 claimed_at = NULL, claimed_by = NULL, updated_at = NOW()
             WHERE status = 'sending' AND claimed_at < :before",
            ['before' => $this->instant($before)],
        );
    }

    public function deliveries(string $tenantId, int $page, int $perPage, array $filters = []): array
    {
        $where  = ['d.tenant_id = :tenant'];
        $params = ['tenant' => $tenantId];

        if (isset($filters['status']) && $filters['status'] instanceof DeliveryStatus) {
            $where[]          = 'd.status = :status';
            $params['status'] = $filters['status']->value;
        }

        if (isset($filters['channel']) && $filters['channel'] instanceof NotificationChannel) {
            $where[]           = 'd.channel = :channel';
            $params['channel'] = $filters['channel']->value;
        }

        $clause = implode(' AND ', $where);

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM notification_deliveries d WHERE {$clause}",
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            "SELECT d.id, d.channel, d.status, d.attempts, d.max_attempts,
                    d.scheduled_for, d.sent_at, d.failed_at, d.error,
                    n.id AS notification_id, n.type, n.title, n.user_id
             FROM notification_deliveries d
             JOIN notifications n ON n.id = d.notification_id
             WHERE {$clause}
             ORDER BY d.created_at DESC, d.id DESC
             LIMIT :limit OFFSET :offset",
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(
                fn (array $row): array => [
                    'id'             => (string) $row['id'],
                    'notificationId' => (string) $row['notification_id'],
                    'userId'         => (string) $row['user_id'],
                    'type'           => (string) $row['type'],
                    'title'          => (string) $row['title'],
                    'channel'        => (string) $row['channel'],
                    'status'         => (string) $row['status'],
                    'attempts'       => (int) $row['attempts'],
                    'maxAttempts'    => (int) $row['max_attempts'],
                    'scheduledFor'   => $this->date($row['scheduled_for'] ?? null)?->format(\DateTimeInterface::ATOM),
                    'sentAt'         => $this->date($row['sent_at'] ?? null)?->format(\DateTimeInterface::ATOM),
                    'failedAt'       => $this->date($row['failed_at'] ?? null)?->format(\DateTimeInterface::ATOM),
                    'error'          => $this->text($row['error'] ?? null),
                ],
                $rows,
            ),
            'total' => $total,
        ];
    }

    // --- tenant configuration ---------------------------------------------

    public function entitledChannels(string $tenantId): array
    {
        $stored = $this->json($this->connection->fetchOne(
            'SELECT entitlements FROM tenants WHERE id = :tenant',
            ['tenant' => $tenantId],
        ));

        $channels = [];

        foreach (self::CHANNEL_ENTITLEMENTS as $key => $channel) {
            if (filter_var($stored[$key] ?? false, FILTER_VALIDATE_BOOL)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    // --- preferences ------------------------------------------------------

    public function preferencesFor(string $tenantId, string $userId): array
    {
        return $this->preferencesByUser($tenantId, [$userId])[$userId] ?? [];
    }

    public function savePreference(NotificationPreference $preference): NotificationPreference
    {
        // Two upserts, because the uniqueness is enforced by two partial
        // indexes and ON CONFLICT has to name the one it means. A single
        // index over (user, type, channel) would not work: NULL is not equal
        // to itself, so channel-wide rows would accumulate without limit.
        $sql = $preference->type === null
            ? 'INSERT INTO notification_preferences (tenant_id, user_id, type, channel, enabled)
               VALUES (:tenant, :user, NULL, :channel, :enabled)
               ON CONFLICT (tenant_id, user_id, channel) WHERE type IS NULL
               DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = NOW()
               RETURNING id'
            : 'INSERT INTO notification_preferences (tenant_id, user_id, type, channel, enabled)
               VALUES (:tenant, :user, :type, :channel, :enabled)
               ON CONFLICT (tenant_id, user_id, type, channel) WHERE type IS NOT NULL
               DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = NOW()
               RETURNING id';

        $params = [
            'tenant'  => $preference->tenantId,
            'user'    => $preference->userId,
            'channel' => $preference->channel->value,
            'enabled' => $preference->enabled,
        ];

        if ($preference->type !== null) {
            $params['type'] = $preference->type->value;
        }

        $id = (string) $this->connection->fetchOne($sql, $params, ['enabled' => \Doctrine\DBAL\ParameterType::BOOLEAN]);

        return new NotificationPreference(
            $id,
            $preference->tenantId,
            $preference->userId,
            $preference->type,
            $preference->channel,
            $preference->enabled,
        );
    }

    public function saveQuietHours(string $tenantId, string $userId, ?QuietHours $quietHours): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET quiet_hours_start = :start, quiet_hours_end = :end, updated_at = NOW()
             WHERE id = :user AND tenant_id = :tenant',
            [
                'user'   => $userId,
                'tenant' => $tenantId,
                'start'  => $quietHours?->start(),
                'end'    => $quietHours?->end(),
            ],
        );
    }

    // --- schedules --------------------------------------------------------

    public function syncSchedules(
        string $tenantId,
        string $subjectType,
        string $subjectId,
        NotificationType $type,
        array $schedules,
    ): array {
        $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $subjectType, $subjectId, $type, $schedules): void {
                $wanted = array_map(
                    static fn (NotificationSchedule $s): int => $s->offsetMinutes,
                    $schedules,
                );

                // Offsets no longer configured are cancelled rather than
                // deleted, and only while still pending: one that has already
                // fired is a record of a message somebody received, and
                // removing it would make the same reminder eligible to go out
                // again if the offset were ever restored.
                $tx->executeStatement(
                    sprintf(
                        "UPDATE notification_schedules
                         SET status = 'cancelled', updated_at = NOW()
                         WHERE tenant_id = :tenant AND subject_type = :subjectType
                           AND subject_id = :subjectId AND type = :type
                           AND status = 'pending' %s",
                        $wanted === [] ? '' : 'AND offset_minutes NOT IN (:offsets)',
                    ),
                    array_filter([
                        'tenant'      => $tenantId,
                        'subjectType' => $subjectType,
                        'subjectId'   => $subjectId,
                        'type'        => $type->value,
                        'offsets'     => $wanted === [] ? null : $wanted,
                    ], static fn (mixed $v): bool => $v !== null),
                    $wanted === [] ? [] : ['offsets' => ArrayParameterType::INTEGER],
                );

                foreach ($schedules as $schedule) {
                    // DO NOTHING, not DO UPDATE: a schedule that already fired
                    // must not be reopened by saving the session again, which
                    // would send its reminder a second time.
                    $tx->executeStatement(
                        'INSERT INTO notification_schedules
                            (tenant_id, type, subject_type, subject_id, offset_minutes, channels)
                         VALUES (:tenant, :type, :subjectType, :subjectId, :offset, :channels::text[])
                         ON CONFLICT (subject_type, subject_id, type, offset_minutes) DO NOTHING',
                        [
                            'tenant'      => $schedule->tenantId,
                            'type'        => $schedule->type->value,
                            'subjectType' => $schedule->subjectType,
                            'subjectId'   => $schedule->subjectId,
                            'offset'      => $schedule->offsetMinutes,
                            'channels'    => '{' . implode(',', array_map(
                                static fn (NotificationChannel $c): string => $c->value,
                                $schedule->channels,
                            )) . '}',
                        ],
                    );
                }
            },
        );

        return $this->schedulesFor($tenantId, $subjectType, $subjectId);
    }

    public function schedulesFor(string $tenantId, string $subjectType, string $subjectId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM notification_schedules
             WHERE tenant_id = :tenant AND subject_type = :subjectType AND subject_id = :subjectId
             ORDER BY offset_minutes DESC',
            ['tenant' => $tenantId, 'subjectType' => $subjectType, 'subjectId' => $subjectId],
        );

        return array_map(fn (array $row): NotificationSchedule => $this->hydrateSchedule($row), $rows);
    }

    public function dueSchedules(\DateTimeImmutable $now, int $limit = 50): array
    {
        // The join is what makes the design work: `s.starts_at` is read live,
        // so a session moved since the schedule was created is already
        // accounted for and no stored due time can go stale.
        //
        // The window narrows on the session rather than on a due column —
        // anything starting within thirty days and not yet finished. The
        // exact due test is the offset arithmetic, done in the domain.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT sch.*, s.starts_at, COALESCE(NULLIF(btrim(s.title), ''), t.title) AS subject_title,
                    t.id AS training_id
             FROM notification_schedules sch
             JOIN training_sessions s ON s.id = sch.subject_id AND s.tenant_id = sch.tenant_id
             JOIN live_trainings t ON t.id = s.training_id
             WHERE sch.status = 'pending'
               AND sch.subject_type = 'training_session'
               AND s.deleted_at IS NULL
               AND s.status NOT IN ('cancelled')
               AND s.starts_at > :now::timestamptz - interval '1 day'
               AND s.starts_at < :now::timestamptz + interval '30 days'
               AND s.starts_at - (sch.offset_minutes * interval '1 minute') <= :now::timestamptz
             ORDER BY s.starts_at
             LIMIT :limit",
            ['now' => $this->instant($now), 'limit' => $limit],
        );

        if ($rows === []) {
            return [];
        }

        // array_values: array_unique keeps the original keys, and a gappy
        // array is not a list.
        $audiences = $this->audiencesFor(array_values(array_unique(array_column($rows, 'training_id'))));

        return array_map(
            fn (array $row): DueSchedule => new DueSchedule(
                schedule: $this->hydrateSchedule($row),
                subjectStartsAt: $this->date($row['starts_at'] ?? null) ?? new \DateTimeImmutable('@0'),
                subjectTitle: (string) ($row['subject_title'] ?? 'your session'),
                audience: $audiences[(string) $row['training_id']] ?? [],
            ),
            $rows,
        );
    }

    public function markScheduleExpanded(string $scheduleId, \DateTimeImmutable $at, int $recipients): void
    {
        $this->connection->executeStatement(
            "UPDATE notification_schedules
             SET status = 'expanded', expanded_at = :at, recipients = :recipients,
                 error = NULL, updated_at = NOW()
             WHERE id = :id",
            ['id' => $scheduleId, 'at' => $this->instant($at), 'recipients' => $recipients],
        );
    }

    public function markScheduleSkipped(string $scheduleId, string $reason): void
    {
        $this->connection->executeStatement(
            "UPDATE notification_schedules
             SET status = 'skipped', error = :reason, updated_at = NOW()
             WHERE id = :id",
            ['id' => $scheduleId, 'reason' => $reason],
        );
    }

    public function markScheduleFailed(string $scheduleId, string $error): void
    {
        $this->connection->executeStatement(
            "UPDATE notification_schedules
             SET status = 'failed', error = :error, updated_at = NOW()
             WHERE id = :id",
            ['id' => $scheduleId, 'error' => $error],
        );
    }

    public function cancelSchedules(string $tenantId, string $subjectType, string $subjectId): int
    {
        return (int) $this->connection->executeStatement(
            "UPDATE notification_schedules
             SET status = 'cancelled', updated_at = NOW()
             WHERE tenant_id = :tenant AND subject_type = :subjectType
               AND subject_id = :subjectId AND status = 'pending'",
            ['tenant' => $tenantId, 'subjectType' => $subjectType, 'subjectId' => $subjectId],
        );
    }

    // --- internals --------------------------------------------------------

    /**
     * Who to tell about each training's sessions.
     *
     * Enrolled, not dropped, not waitlisted — someone on the waiting list has
     * no place to be reminded about, and telling them to attend would be
     * worse than telling them nothing.
     *
     * @param  list<mixed>               $trainingIds
     * @return array<string, list<string>>
     */
    private function audiencesFor(array $trainingIds): array
    {
        $ids = array_values(array_filter(array_map('strval', $trainingIds)));

        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT training_id, user_id FROM training_enrollments
             WHERE training_id IN (:ids) AND deleted_at IS NULL
               AND status <> 'dropped' AND NOT waitlisted",
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $audiences = [];

        foreach ($rows as $row) {
            $audiences[(string) $row['training_id']][] = (string) $row['user_id'];
        }

        return $audiences;
    }

    /**
     * @param  list<mixed> $userIds
     * @return array<string, list<NotificationPreference>>
     */
    private function preferencesByUser(string $tenantId, array $userIds): array
    {
        $ids = array_values(array_filter(array_map('strval', $userIds)));

        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM notification_preferences WHERE tenant_id = :tenant AND user_id IN (:ids)',
            ['tenant' => $tenantId, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $preferences = [];

        foreach ($rows as $row) {
            $channel = NotificationChannel::tryFrom((string) $row['channel']);

            if ($channel === null) {
                continue;
            }

            $preferences[(string) $row['user_id']][] = new NotificationPreference(
                id: (string) $row['id'],
                tenantId: $tenantId,
                userId: (string) $row['user_id'],
                type: $row['type'] === null ? null : NotificationType::tryFrom((string) $row['type']),
                channel: $channel,
                enabled: (bool) $row['enabled'],
            );
        }

        return $preferences;
    }

    /**
     * The tenant's timezone, for quiet hours.
     *
     * Read once per batch rather than per recipient. Falls back to UTC, which
     * makes quiet hours slightly wrong rather than absent — and a message
     * arriving an hour early beats one that never arrives.
     */
    private function timezoneFor(string $tenantId): string
    {
        $settings = $this->json($this->connection->fetchOne(
            'SELECT settings FROM tenants WHERE id = :tenant',
            ['tenant' => $tenantId],
        ));

        $timezone = $settings['timezone'] ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Notification
    {
        return new Notification(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            userId: (string) $row['user_id'],
            type: NotificationType::from((string) $row['type']),
            title: (string) $row['title'],
            body: (string) ($row['body'] ?? ''),
            subjectType: $this->text($row['subject_type'] ?? null),
            subjectId: $this->text($row['subject_id'] ?? null),
            data: $this->json($row['data'] ?? null),
            priority: NotificationPriority::from((string) $row['priority']),
            readAt: $this->date($row['read_at'] ?? null),
            expiresAt: $this->date($row['expires_at'] ?? null),
            createdAt: $this->date($row['created_at'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateDelivery(array $row): NotificationDelivery
    {
        return new NotificationDelivery(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            notificationId: (string) $row['notification_id'],
            channel: NotificationChannel::from((string) $row['channel']),
            address: $this->text($row['address'] ?? null),
            status: DeliveryStatus::from((string) $row['status']),
            attempts: (int) $row['attempts'],
            maxAttempts: (int) $row['max_attempts'],
            scheduledFor: $this->date($row['scheduled_for'] ?? null),
            claimedAt: $this->date($row['claimed_at'] ?? null),
            claimedBy: $this->text($row['claimed_by'] ?? null),
            sentAt: $this->date($row['sent_at'] ?? null),
            failedAt: $this->date($row['failed_at'] ?? null),
            error: $this->text($row['error'] ?? null),
            providerMessageId: $this->text($row['provider_message_id'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSchedule(array $row): NotificationSchedule
    {
        return new NotificationSchedule(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            type: NotificationType::from((string) $row['type']),
            subjectType: (string) $row['subject_type'],
            subjectId: (string) $row['subject_id'],
            offsetMinutes: (int) $row['offset_minutes'],
            channels: NotificationChannel::fromList($this->pgArray($row['channels'] ?? null)),
            status: ScheduleStatus::from((string) $row['status']),
            expandedAt: $this->date($row['expanded_at'] ?? null),
            recipients: $row['recipients'] === null ? null : (int) $row['recipients'],
            error: $this->text($row['error'] ?? null),
            createdAt: $this->date($row['created_at'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    private function pgArray(mixed $value): array
    {
        if (!is_string($value) || $value === '' || $value === '{}') {
            return [];
        }

        $inner = trim($value, '{}');

        return $inner === ''
            ? []
            : array_values(array_map(static fn (string $v): string => trim($v, '"'), explode(',', $inner)));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A timestamp PostgreSQL can compare without losing a fraction of a second.
     *
     * `DateTimeInterface::ATOM` truncates to whole seconds, and the columns
     * here are compared against `now()`, which does not. A delivery written at
     * 07:28:13.903 is invisible to a dispatcher asking for everything due by
     * 07:28:13.000 — for a whole second, on every run, which is exactly long
     * enough to look like an intermittent fault and never be reproduced.
     */
    private function instant(\DateTimeInterface $moment): string
    {
        return $moment->format('Y-m-d\TH:i:s.uP');
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
