<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Messaging\Conversation;
use Central\Api\Domain\Messaging\ConversationKind;
use Central\Api\Domain\Messaging\DirectKey;
use Central\Api\Domain\Messaging\Message;
use Central\Api\Domain\Messaging\MessagingRepository;
use Central\Api\Domain\Messaging\Participant;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalMessagingRepository implements MessagingRepository
{
    private const COLUMNS = 'c.id, c.tenant_id, c.kind, c.name, c.description,
                             c.created_by, c.created_at, c.archived_at';

    /**
     * How many messages, and how many this person has not read.
     *
     * Both counted. `conversations.message_count` and
     * `conversation_participants.unread_count` were columns every send had to
     * fan out an UPDATE to maintain — one write per participant, per message,
     * to say something the messages already say.
     */
    private const COUNTS = '(
            SELECT COUNT(*) FROM messages m
            WHERE m.conversation_id = c.id AND m.deleted_at IS NULL
        ) AS message_count,
        (
            SELECT COUNT(*) FROM messages m
            WHERE m.conversation_id = c.id
              AND m.deleted_at IS NULL
              AND m.sender_id IS DISTINCT FROM :viewer
              AND (me.last_read_at IS NULL OR m.created_at > me.last_read_at)
        ) AS unread_count';

    private const VIEWER_JOIN = 'JOIN conversation_participants me
                                      ON me.conversation_id = c.id
                                     AND me.user_id = :viewer
                                     AND me.left_at IS NULL';

    private const VIEWER_COLUMNS = 'me.role AS my_role, me.is_muted, me.is_pinned, me.last_read_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    // --- conversations ----------------------------------------------------

    public function inbox(string $tenantId, string $userId, bool $archived, int $page, int $perPage): array
    {
        $where = 'c.tenant_id = :tenant AND c.archived_at IS ' . ($archived ? 'NOT NULL' : 'NULL');

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM conversations c %s WHERE %s', self::VIEWER_JOIN, $where),
            ['tenant' => $tenantId, 'viewer' => $userId],
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s, %s,
                        (
                            SELECT MAX(m.created_at) FROM messages m
                            WHERE m.conversation_id = c.id AND m.deleted_at IS NULL
                        ) AS last_message_at
                 FROM conversations c
                 %s
                 WHERE %s
                 -- Pinned first, then whatever moved most recently. A
                 -- conversation with nothing in it sorts by when it was made.
                 ORDER BY me.is_pinned DESC,
                          COALESCE((
                              SELECT MAX(m.created_at) FROM messages m
                              WHERE m.conversation_id = c.id AND m.deleted_at IS NULL
                          ), c.created_at) DESC,
                          c.id
                 LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::VIEWER_COLUMNS,
                self::COUNTS,
                self::VIEWER_JOIN,
                $where,
            ),
            [
                'tenant' => $tenantId,
                'viewer' => $userId,
                'limit'  => $perPage,
                'offset' => ($page - 1) * $perPage,
            ],
        );

        return [
            'items' => $this->hydrateAll($tenantId, $rows, withLastMessage: true),
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $userId, string $id): ?Conversation
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s, %s FROM conversations c %s
                 WHERE c.id = :id AND c.tenant_id = :tenant',
                self::COLUMNS,
                self::VIEWER_COLUMNS,
                self::COUNTS,
                self::VIEWER_JOIN,
            ),
            ['id' => $id, 'tenant' => $tenantId, 'viewer' => $userId],
        );

        if ($row === false) {
            // Not theirs, or not there. Deliberately the same answer: telling
            // somebody a conversation exists but is not theirs is telling them
            // who is talking.
            return null;
        }

        return $this->hydrateAll($tenantId, [$row], withLastMessage: false)[0];
    }

    public function directWith(string $tenantId, string $userId, string $otherId): Conversation
    {
        $key = DirectKey::between($userId, $otherId);

        $id = $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $userId, $otherId, $key): string {
                // Find or create. The unique index makes a second conversation
                // between a pair impossible; ON CONFLICT makes two taps produce
                // one conversation rather than an error.
                $id = $tx->fetchOne(
                    "INSERT INTO conversations (tenant_id, kind, direct_key, created_by)
                     VALUES (:tenant, 'direct', :key, :creator)
                     ON CONFLICT (tenant_id, direct_key) WHERE direct_key IS NOT NULL DO NOTHING
                     RETURNING id",
                    ['tenant' => $tenantId, 'key' => (string) $key, 'creator' => $userId],
                );

                if (!is_string($id) || $id === '') {
                    return (string) $tx->fetchOne(
                        'SELECT id FROM conversations WHERE tenant_id = :tenant AND direct_key = :key',
                        ['tenant' => $tenantId, 'key' => (string) $key],
                    );
                }

                foreach ([$userId, $otherId] as $participant) {
                    $tx->executeStatement(
                        "INSERT INTO conversation_participants (conversation_id, user_id, tenant_id, role)
                         VALUES (:conversation, :user, :tenant, 'member')
                         ON CONFLICT DO NOTHING",
                        ['conversation' => $id, 'user' => $participant, 'tenant' => $tenantId],
                    );
                }

                return $id;
            },
        );

        return $this->find($tenantId, $userId, $id)
            ?? throw new \RuntimeException('The conversation vanished immediately after being started.');
    }

    public function startGroup(
        string $tenantId,
        string $creatorId,
        ConversationKind $kind,
        string $name,
        ?string $description,
        array $participantIds,
    ): Conversation {
        $id = $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $creatorId, $kind, $name, $description, $participantIds): string {
                $id = (string) $tx->fetchOne(
                    'INSERT INTO conversations (tenant_id, kind, name, description, created_by)
                     VALUES (:tenant, :kind, :name, :description, :creator)
                     RETURNING id',
                    [
                        'tenant'      => $tenantId,
                        'kind'        => $kind->value,
                        'name'        => $name,
                        'description' => $description,
                        'creator'     => $creatorId,
                    ],
                );

                $tx->executeStatement(
                    "INSERT INTO conversation_participants (conversation_id, user_id, tenant_id, role)
                     VALUES (:conversation, :user, :tenant, 'owner')",
                    ['conversation' => $id, 'user' => $creatorId, 'tenant' => $tenantId],
                );

                $others = array_values(array_filter(
                    array_unique($participantIds),
                    static fn (string $u): bool => $u !== $creatorId,
                ));

                if ($others !== []) {
                    $this->insertParticipants($tx, $tenantId, $id, $others);
                }

                return $id;
            },
        );

        return $this->find($tenantId, $creatorId, $id)
            ?? throw new \RuntimeException('The conversation vanished immediately after being started.');
    }

    public function rename(string $tenantId, string $id, string $name, ?string $description): ?Conversation
    {
        $changed = $this->connection->executeStatement(
            "UPDATE conversations
             SET name = :name, description = :description, updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND kind <> 'direct'",
            ['id' => $id, 'tenant' => $tenantId, 'name' => $name, 'description' => $description],
        );

        if ($changed === 0) {
            return null;
        }

        // Read back as the creator would see it — the caller re-reads for the
        // person who asked.
        return $this->findAny($tenantId, $id);
    }

    public function addParticipants(string $tenantId, string $conversationId, array $userIds, string $addedBy): int
    {
        $userIds = array_values(array_unique($userIds));

        if ($userIds === []) {
            return 0;
        }

        return $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $conversationId, $userIds): int {
                return $this->insertParticipants($tx, $tenantId, $conversationId, $userIds);
            },
        );
    }

    public function removeParticipant(string $tenantId, string $conversationId, string $userId): bool
    {
        // Marked as left, not deleted. Their messages stay and still carry
        // their name; removing the row leaves a history referring to somebody
        // nobody can see.
        return $this->connection->executeStatement(
            'UPDATE conversation_participants SET left_at = now()
             WHERE conversation_id = :conversation AND user_id = :user
               AND tenant_id = :tenant AND left_at IS NULL',
            ['conversation' => $conversationId, 'user' => $userId, 'tenant' => $tenantId],
        ) > 0;
    }

    public function setArchived(string $tenantId, string $id, bool $archived): ?Conversation
    {
        $changed = $this->connection->executeStatement(
            'UPDATE conversations
             SET archived_at = CASE WHEN :archived::boolean THEN now() ELSE NULL END, updated_at = now()
             WHERE id = :id AND tenant_id = :tenant',
            ['id' => $id, 'tenant' => $tenantId, 'archived' => $archived],
            ['archived' => ParameterType::BOOLEAN],
        );

        return $changed === 0 ? null : $this->findAny($tenantId, $id);
    }

    public function setViewState(
        string $tenantId,
        string $conversationId,
        string $userId,
        ?bool $muted,
        ?bool $pinned,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE conversation_participants SET
                 is_muted = COALESCE(:muted::boolean, is_muted),
                 is_pinned = COALESCE(:pinned::boolean, is_pinned)
             WHERE conversation_id = :conversation AND user_id = :user AND tenant_id = :tenant',
            [
                'conversation' => $conversationId,
                'user'         => $userId,
                'tenant'       => $tenantId,
                'muted'        => $muted,
                'pinned'       => $pinned,
            ],
            ['muted' => ParameterType::BOOLEAN, 'pinned' => ParameterType::BOOLEAN],
        ) > 0;
    }

    // --- messages ---------------------------------------------------------

    public function messages(
        string $tenantId,
        string $conversationId,
        int $page,
        int $perPage,
        ?string $search = null,
    ): array {
        $where  = ['m.conversation_id = :conversation', 'm.tenant_id = :tenant'];
        $params = ['conversation' => $conversationId, 'tenant' => $tenantId];

        if ($search !== null) {
            $where[]          = 'm.content ILIKE :search AND m.deleted_at IS NULL';
            $params['search'] = '%' . $search . '%';
        }

        $clause = implode(' AND ', $where);

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM messages m WHERE %s', $clause),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT %s FROM messages m
                 LEFT JOIN users u ON u.id = m.sender_id
                 LEFT JOIN messages r ON r.id = m.reply_to_id
                 LEFT JOIN users ru ON ru.id = r.sender_id
                 WHERE %s
                 ORDER BY m.created_at DESC, m.id DESC
                 LIMIT :limit OFFSET :offset",
                $this->messageColumns(),
                $clause,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $row): Message => $this->hydrateMessage($row), $rows),
            'total' => $total,
        ];
    }

    public function findMessage(string $tenantId, string $id): ?Message
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM messages m
                 LEFT JOIN users u ON u.id = m.sender_id
                 LEFT JOIN messages r ON r.id = m.reply_to_id
                 LEFT JOIN users ru ON ru.id = r.sender_id
                 WHERE m.id = :id AND m.tenant_id = :tenant',
                $this->messageColumns(),
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateMessage($row);
    }

    public function send(Message $message): Message
    {
        $id = (string) $this->connection->fetchOne(
            'INSERT INTO messages (tenant_id, conversation_id, sender_id, content, kind, reply_to_id)
             VALUES (:tenant, :conversation, :sender, :content, :kind, :replyTo)
             RETURNING id',
            [
                'tenant'       => $message->tenantId,
                'conversation' => $message->conversationId,
                'sender'       => $message->senderId,
                'content'      => $message->content,
                'kind'         => $message->kind,
                'replyTo'      => $message->replyToId,
            ],
        );

        // No counters to bump. The legacy updated `conversations.message_count`,
        // `last_message_id`, `last_message_at` and every participant's
        // `unread_count` on every send.
        return $this->findMessage($message->tenantId, $id)
            ?? throw new \RuntimeException('The message vanished immediately after being sent.');
    }

    public function edit(string $tenantId, string $id, string $senderId, string $content): ?Message
    {
        // Only the author, and only while it stands. Editing somebody else's
        // words is not moderation.
        $changed = $this->connection->executeStatement(
            "UPDATE messages SET content = :content, edited_at = now()
             WHERE id = :id AND tenant_id = :tenant AND sender_id = :sender
               AND deleted_at IS NULL AND kind = 'text'",
            ['id' => $id, 'tenant' => $tenantId, 'sender' => $senderId, 'content' => $content],
        );

        return $changed === 0 ? null : $this->findMessage($tenantId, $id);
    }

    public function deleteMessage(string $tenantId, string $id, string $deletedBy): bool
    {
        return $this->connection->executeStatement(
            'UPDATE messages SET deleted_at = now(), deleted_by = :by
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId, 'by' => $deletedBy],
        ) > 0;
    }

    public function markRead(string $tenantId, string $conversationId, string $userId): bool
    {
        return $this->connection->executeStatement(
            'UPDATE conversation_participants SET last_read_at = now()
             WHERE conversation_id = :conversation AND user_id = :user AND tenant_id = :tenant',
            ['conversation' => $conversationId, 'user' => $userId, 'tenant' => $tenantId],
        ) > 0;
    }

    public function unreadTotal(string $tenantId, string $userId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM messages m
             JOIN conversation_participants p
                  ON p.conversation_id = m.conversation_id
                 AND p.user_id = :user
                 AND p.left_at IS NULL
             JOIN conversations c ON c.id = m.conversation_id AND c.archived_at IS NULL
             WHERE m.tenant_id = :tenant
               AND m.deleted_at IS NULL
               AND m.sender_id IS DISTINCT FROM :user
               AND (p.last_read_at IS NULL OR m.created_at > p.last_read_at)',
            ['tenant' => $tenantId, 'user' => $userId],
        );
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param list<string> $userIds
     */
    private function insertParticipants(
        Connection $tx,
        string $tenantId,
        string $conversationId,
        array $userIds,
    ): int {
        // Through `users`, so somebody from another organisation cannot be
        // added by id — and re-adding somebody who left brings them back
        // rather than failing on the primary key.
        return (int) $tx->executeStatement(
            "INSERT INTO conversation_participants (conversation_id, user_id, tenant_id, role)
             SELECT :conversation, u.id, :tenant, 'member'
             FROM users u
             WHERE u.id IN (:ids) AND u.tenant_id = :tenant AND u.deleted_at IS NULL
             ON CONFLICT (conversation_id, user_id) DO UPDATE
             SET left_at = NULL, joined_at = now()",
            ['conversation' => $conversationId, 'tenant' => $tenantId, 'ids' => $userIds],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    /**
     * A conversation without a viewer, for an administrative read-back.
     */
    private function findAny(string $tenantId, string $id): ?Conversation
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s,
                        (
                            SELECT COUNT(*) FROM messages m
                            WHERE m.conversation_id = c.id AND m.deleted_at IS NULL
                        ) AS message_count,
                        0 AS unread_count
                 FROM conversations c
                 WHERE c.id = :id AND c.tenant_id = :tenant',
                self::COLUMNS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateAll($tenantId, [$row], withLastMessage: false)[0];
    }

    /**
     * @param  list<array<string, mixed>> $rows
     * @return list<Conversation>
     */
    private function hydrateAll(string $tenantId, array $rows, bool $withLastMessage): array
    {
        if ($rows === []) {
            return [];
        }

        $ids          = array_map(static fn (array $r): string => (string) $r['id'], $rows);
        $participants = $this->participantsFor($tenantId, $ids);
        $lastMessages = $withLastMessage ? $this->lastMessagesFor($tenantId, $ids) : [];

        return array_map(
            function (array $row) use ($participants, $lastMessages): Conversation {
                $id     = (string) $row['id'];
                $viewer = null;

                if (array_key_exists('my_role', $row)) {
                    $viewer = new Participant(
                        userId: '',
                        role: (string) $row['my_role'],
                        isMuted: (bool) $row['is_muted'],
                        isPinned: (bool) $row['is_pinned'],
                        lastReadAt: $this->date($row['last_read_at'] ?? null),
                    );
                }

                return new Conversation(
                    id: $id,
                    tenantId: (string) $row['tenant_id'],
                    kind: ConversationKind::from((string) $row['kind']),
                    name: $this->text($row['name'] ?? null),
                    description: $this->text($row['description'] ?? null),
                    createdBy: $this->text($row['created_by'] ?? null),
                    createdAt: $this->date($row['created_at'] ?? null),
                    archivedAt: $this->date($row['archived_at'] ?? null),
                    participants: $participants[$id] ?? [],
                    messageCount: (int) ($row['message_count'] ?? 0),
                    unreadCount: (int) ($row['unread_count'] ?? 0),
                    lastMessage: $lastMessages[$id] ?? null,
                    viewer: $viewer,
                );
            },
            $rows,
        );
    }

    /**
     * Everybody in a set of conversations, in one query.
     *
     * @param  list<string>                    $ids
     * @return array<string, list<Participant>>
     */
    private function participantsFor(string $tenantId, array $ids): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.conversation_id, p.user_id, p.role, p.is_muted, p.is_pinned,
                    p.last_read_at, p.joined_at, p.left_at,
                    u.email, u.avatar_url,
                    NULLIF(btrim(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')), '') AS full_name
             FROM conversation_participants p
             JOIN users u ON u.id = p.user_id
             WHERE p.conversation_id IN (:ids) AND p.tenant_id = :tenant
             ORDER BY p.joined_at, p.user_id",
            ['ids' => $ids, 'tenant' => $tenantId],
            ['ids' => ArrayParameterType::STRING],
        );

        $byConversation = [];

        foreach ($rows as $row) {
            $byConversation[(string) $row['conversation_id']][] = new Participant(
                userId: (string) $row['user_id'],
                role: (string) $row['role'],
                name: $this->text($row['full_name'] ?? null),
                email: $this->text($row['email'] ?? null),
                avatarUrl: $this->text($row['avatar_url'] ?? null),
                isMuted: (bool) $row['is_muted'],
                isPinned: (bool) $row['is_pinned'],
                lastReadAt: $this->date($row['last_read_at'] ?? null),
                joinedAt: $this->date($row['joined_at'] ?? null),
                leftAt: $this->date($row['left_at'] ?? null),
            );
        }

        return $byConversation;
    }

    /**
     * The newest message of each conversation, in one query.
     *
     * `DISTINCT ON` rather than a correlated subquery per row, and rather than
     * the `last_message_id` column the legacy kept in step by hand.
     *
     * @param  list<string>           $ids
     * @return array<string, Message>
     */
    private function lastMessagesFor(string $tenantId, array $ids): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT DISTINCT ON (m.conversation_id) %s
                 FROM messages m
                 LEFT JOIN users u ON u.id = m.sender_id
                 LEFT JOIN messages r ON r.id = m.reply_to_id
                 LEFT JOIN users ru ON ru.id = r.sender_id
                 WHERE m.conversation_id IN (:ids) AND m.tenant_id = :tenant
                 ORDER BY m.conversation_id, m.created_at DESC, m.id DESC',
                $this->messageColumns(),
            ),
            ['ids' => $ids, 'tenant' => $tenantId],
            ['ids' => ArrayParameterType::STRING],
        );

        $latest = [];

        foreach ($rows as $row) {
            $latest[(string) $row['conversation_id']] = $this->hydrateMessage($row);
        }

        return $latest;
    }

    private function messageColumns(): string
    {
        return "m.id, m.tenant_id, m.conversation_id, m.sender_id, m.content, m.kind,
                m.reply_to_id, m.edited_at, m.deleted_at, m.deleted_by, m.created_at,
                u.avatar_url AS sender_avatar_url,
                NULLIF(btrim(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')), '')
                    AS sender_name,
                r.content AS reply_content,
                r.deleted_at AS reply_deleted_at,
                NULLIF(btrim(COALESCE(ru.first_name, '') || ' ' || COALESCE(ru.last_name, '')), '')
                    AS reply_sender_name";
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateMessage(array $row): Message
    {
        // A reply to something since deleted shows the same placeholder the
        // message itself would, rather than the words that were taken down.
        $replyContent = $this->text($row['reply_content'] ?? null);

        if ($replyContent !== null && ($row['reply_deleted_at'] ?? null) !== null) {
            $replyContent = 'This message was deleted.';
        }

        return new Message(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            conversationId: (string) $row['conversation_id'],
            content: (string) $row['content'],
            senderId: $this->text($row['sender_id'] ?? null),
            kind: (string) $row['kind'],
            replyToId: $this->text($row['reply_to_id'] ?? null),
            editedAt: $this->date($row['edited_at'] ?? null),
            deletedAt: $this->date($row['deleted_at'] ?? null),
            deletedBy: $this->text($row['deleted_by'] ?? null),
            createdAt: $this->date($row['created_at'] ?? null),
            senderName: $this->text($row['sender_name'] ?? null),
            senderAvatarUrl: $this->text($row['sender_avatar_url'] ?? null),
            replyToContent: $replyContent,
            replyToSenderName: $this->text($row['reply_sender_name'] ?? null),
        );
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
