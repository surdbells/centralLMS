<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Audience\AudienceResolver;
use Central\Api\Domain\Audience\AudienceTarget;
use Central\Api\Domain\Messaging\Broadcast;
use Central\Api\Domain\Messaging\BroadcastPriority;
use Central\Api\Domain\Messaging\BroadcastRepository;
use Central\Api\Domain\Messaging\BroadcastStatus;
use Central\Api\Domain\Messaging\BroadcastTarget;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalBroadcastRepository implements BroadcastRepository
{
    private const COLUMNS = "b.id, b.tenant_id, b.title, b.content, b.target_type,
                             b.target_criteria, b.priority, b.requires_acknowledgment,
                             b.scheduled_at, b.expires_at, b.status, b.sent_at,
                             b.created_by, b.created_at, b.updated_at,
                             TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS sender_name";

    /**
     * The three figures an author watches, counted rather than stored.
     *
     * A stored count is wrong from the first time somebody opens the
     * announcement and never right again.
     */
    private const COUNTS = '(
                                SELECT COUNT(*) FROM broadcast_recipients r
                                WHERE r.broadcast_id = b.id
                            ) AS recipient_count,
                            (
                                SELECT COUNT(*) FROM broadcast_recipients r
                                WHERE r.broadcast_id = b.id AND r.read_at IS NOT NULL
                            ) AS read_count,
                            (
                                SELECT COUNT(*) FROM broadcast_recipients r
                                WHERE r.broadcast_id = b.id AND r.acknowledged_at IS NOT NULL
                            ) AS acknowledged_count';

    private const JOIN = 'LEFT JOIN users u ON u.id = b.created_by';

    public function __construct(
        private readonly Connection $connection,
        private readonly AudienceResolver $audience,
    ) {
    }

    public function authored(string $tenantId, ?string $authorId, int $page, int $perPage): array
    {
        $where = 'b.tenant_id = :tenant AND b.deleted_at IS NULL';
        $params = ['tenant' => $tenantId];

        if ($authorId !== null) {
            $where .= ' AND b.created_by = :author';
            $params['author'] = $authorId;
        }

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM broadcasts b WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM broadcasts b %s
                 WHERE %s
                 ORDER BY b.created_at DESC
                 LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::COUNTS,
                self::JOIN,
                $where,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $r): Broadcast => $this->hydrate($r), $rows),
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $id): ?Broadcast
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM broadcasts b %s
                 WHERE b.id = :id AND b.tenant_id = :tenant AND b.deleted_at IS NULL',
                self::COLUMNS,
                self::COUNTS,
                self::JOIN,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function save(Broadcast $broadcast): Broadcast
    {
        $this->connection->executeStatement(
            'INSERT INTO broadcasts
                 (id, tenant_id, title, content, target_type, target_criteria, priority,
                  requires_acknowledgment, scheduled_at, expires_at, status, created_by)
             VALUES (:id, :tenant, :title, :content, :target, :criteria, :priority,
                     :ack, :scheduled, :expires, :status, :by)
             ON CONFLICT (id) DO UPDATE SET
                 title           = EXCLUDED.title,
                 content         = EXCLUDED.content,
                 target_type     = EXCLUDED.target_type,
                 target_criteria = EXCLUDED.target_criteria,
                 priority        = EXCLUDED.priority,
                 requires_acknowledgment = EXCLUDED.requires_acknowledgment,
                 scheduled_at    = EXCLUDED.scheduled_at,
                 expires_at      = EXCLUDED.expires_at,
                 status          = EXCLUDED.status,
                 updated_at      = NOW()',
            [
                'id'        => $broadcast->id,
                'tenant'    => $broadcast->tenantId,
                'title'     => $broadcast->title,
                'content'   => $broadcast->content,
                'target'    => $broadcast->targetType->value,
                'criteria'  => json_encode($broadcast->targetCriteria, JSON_THROW_ON_ERROR),
                'priority'  => $broadcast->priority->value,
                'ack'       => $broadcast->requiresAcknowledgment,
                'scheduled' => $broadcast->scheduledAt?->format('Y-m-d H:i:sP'),
                'expires'   => $broadcast->expiresAt?->format('Y-m-d H:i:sP'),
                'status'    => $broadcast->status->value,
                'by'        => $broadcast->createdBy,
            ],
            ['ack' => ParameterType::BOOLEAN],
        );

        return $this->find($broadcast->tenantId, $broadcast->id) ?? $broadcast;
    }

    public function delete(string $tenantId, string $id): bool
    {
        // Soft: the acknowledgements already given are somebody's record of
        // having read a policy, and a hard delete takes that with it.
        return $this->connection->executeStatement(
            'UPDATE broadcasts SET deleted_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function send(string $tenantId, string $id): int
    {
        $sent = 0;

        // One transaction: a broadcast marked sent with no recipients is an
        // announcement nobody will see that looks delivered.
        $this->connection->transactional(function () use ($tenantId, $id, &$sent): void {
            $broadcast = $this->find($tenantId, $id);

            if ($broadcast === null || !$broadcast->status->canSend()) {
                return;
            }

            $recipients = $this->resolveAudience($broadcast);

            foreach ($recipients as $userId) {
                $this->connection->executeStatement(
                    'INSERT INTO broadcast_recipients (broadcast_id, user_id, tenant_id)
                     VALUES (:broadcast, :user, :tenant)
                     ON CONFLICT (broadcast_id, user_id) DO NOTHING',
                    ['broadcast' => $id, 'user' => $userId, 'tenant' => $tenantId],
                );
            }

            $this->connection->executeStatement(
                "UPDATE broadcasts SET status = 'sent', sent_at = NOW(), updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant",
                ['id' => $id, 'tenant' => $tenantId],
            );

            $sent = count($recipients);
        });

        return $sent;
    }

    public function cancel(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            "UPDATE broadcasts SET status = 'cancelled', updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND status IN ('draft', 'scheduled')",
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function due(\DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT %s, %s FROM broadcasts b %s
                 WHERE b.status = 'scheduled' AND b.deleted_at IS NULL
                   AND b.scheduled_at <= :now
                 ORDER BY b.scheduled_at
                 LIMIT :limit",
                self::COLUMNS,
                self::COUNTS,
                self::JOIN,
            ),
            ['now' => $now->format('Y-m-d H:i:sP'), 'limit' => $limit],
        );

        return array_map(fn (array $r): Broadcast => $this->hydrate($r), $rows);
    }

    // --- what one person has waiting -----------------------------------------

    public function forRecipient(string $tenantId, string $userId, bool $unreadOnly): array
    {
        $where = [
            'r.tenant_id = :tenant',
            'r.user_id = :user',
            'b.deleted_at IS NULL',
            // An announcement past its date is not news, and one that cannot
            // be cleared is how a list stops being read at all.
            '(b.expires_at IS NULL OR b.expires_at > NOW())',
        ];

        if ($unreadOnly) {
            $where[] = 'r.read_at IS NULL';
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s, r.read_at, r.acknowledged_at
                 FROM broadcast_recipients r
                 JOIN broadcasts b ON b.id = r.broadcast_id
                 %s
                 WHERE %s
                 ORDER BY b.sent_at DESC NULLS LAST, r.created_at DESC',
                self::COLUMNS,
                self::COUNTS,
                self::JOIN,
                implode(' AND ', $where),
            ),
            ['tenant' => $tenantId, 'user' => $userId],
        );

        return array_map(fn (array $r): array => $this->hydrate($r)->toArray([
            'isRead'         => $r['read_at'] !== null,
            'isAcknowledged' => $r['acknowledged_at'] !== null,
            'readAt'         => $r['read_at'] === null ? null : (string) $r['read_at'],
        ]), $rows);
    }

    public function findForRecipient(string $tenantId, string $userId, string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s, r.read_at, r.acknowledged_at
                 FROM broadcast_recipients r
                 JOIN broadcasts b ON b.id = r.broadcast_id
                 %s
                 WHERE r.tenant_id = :tenant AND r.user_id = :user AND r.broadcast_id = :id
                   AND b.deleted_at IS NULL',
                self::COLUMNS,
                self::COUNTS,
                self::JOIN,
            ),
            ['tenant' => $tenantId, 'user' => $userId, 'id' => $id],
        );

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row)->toArray([
            'isRead'         => $row['read_at'] !== null,
            'isAcknowledged' => $row['acknowledged_at'] !== null,
            'readAt'         => $row['read_at'] === null ? null : (string) $row['read_at'],
        ]);
    }

    public function markRead(string $tenantId, string $userId, string $id): bool
    {
        // `read_at IS NULL` so re-opening does not move the first-read time,
        // which is the one an audit asks about.
        return $this->connection->executeStatement(
            'UPDATE broadcast_recipients SET read_at = NOW()
             WHERE tenant_id = :tenant AND user_id = :user AND broadcast_id = :id
               AND read_at IS NULL',
            ['tenant' => $tenantId, 'user' => $userId, 'id' => $id],
        ) > 0;
    }

    public function acknowledge(string $tenantId, string $userId, string $id): bool
    {
        // Read at the same time if it was not already: the check constraint
        // refuses an acknowledgement without one, and rightly — "confirmed
        // without reading" is the state an auditor would query for.
        return $this->connection->executeStatement(
            'UPDATE broadcast_recipients
             SET read_at = COALESCE(read_at, NOW()), acknowledged_at = NOW()
             WHERE tenant_id = :tenant AND user_id = :user AND broadcast_id = :id
               AND acknowledged_at IS NULL',
            ['tenant' => $tenantId, 'user' => $userId, 'id' => $id],
        ) > 0;
    }

    public function unreadCount(string $tenantId, string $userId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM broadcast_recipients r
             JOIN broadcasts b ON b.id = r.broadcast_id
             WHERE r.tenant_id = :tenant AND r.user_id = :user AND r.read_at IS NULL
               AND b.deleted_at IS NULL AND (b.expires_at IS NULL OR b.expires_at > NOW())',
            ['tenant' => $tenantId, 'user' => $userId],
        );
    }

    // --- internals ------------------------------------------------------------

    /**
     * Turns "everybody in Operations" into a list of people.
     *
     * Delegated: a standing path assignment asks the same question, and two
     * copies of it would drift — one counting suspended accounts and the
     * other not, with nobody noticing until a leaver was chased.
     *
     * @return list<string>
     */
    private function resolveAudience(Broadcast $broadcast): array
    {
        return $this->audience->resolve(
            $broadcast->tenantId,
            AudienceTarget::from($broadcast->targetType->value),
            $broadcast->targetCriteria,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Broadcast
    {
        $criteria = $row['target_criteria'] ?? '{}';
        $decoded = is_string($criteria) ? json_decode($criteria, true) : $criteria;
        $name = is_string($row['sender_name'] ?? null) ? trim($row['sender_name']) : '';

        return new Broadcast(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            title: (string) $row['title'],
            content: (string) $row['content'],
            targetType: BroadcastTarget::from((string) $row['target_type']),
            targetCriteria: is_array($decoded) ? $decoded : [],
            priority: BroadcastPriority::from((string) $row['priority']),
            requiresAcknowledgment: (bool) $row['requires_acknowledgment'],
            scheduledAt: $this->at($row['scheduled_at']),
            expiresAt: $this->at($row['expires_at']),
            status: BroadcastStatus::from((string) $row['status']),
            sentAt: $this->at($row['sent_at']),
            createdBy: $row['created_by'] === null ? null : (string) $row['created_by'],
            createdAt: $this->at($row['created_at']),
            updatedAt: $this->at($row['updated_at']),
            recipientCount: (int) ($row['recipient_count'] ?? 0),
            readCount: (int) ($row['read_count'] ?? 0),
            acknowledgedCount: (int) ($row['acknowledged_count'] ?? 0),
            senderName: $name === '' ? null : $name,
        );
    }

    private function at(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? new \DateTimeImmutable($value) : null;
    }
}
