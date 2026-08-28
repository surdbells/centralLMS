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

    // --- reactions -----------------------------------------------------------

    public function toggleReaction(
        string $tenantId,
        string $messageId,
        string $userId,
        string $emoji,
    ): array {
        // One statement rather than a read then a write: two people tapping
        // the same emoji at once would otherwise both see "not mine yet" and
        // both insert, and the unique index would fail one of them.
        $removed = $this->connection->executeStatement(
            'DELETE FROM message_reactions
             WHERE tenant_id = :tenant AND message_id = :message
               AND user_id = :user AND emoji = :emoji',
            ['tenant' => $tenantId, 'message' => $messageId, 'user' => $userId, 'emoji' => $emoji],
        );

        if ($removed === 0) {
            $this->connection->executeStatement(
                'INSERT INTO message_reactions (tenant_id, message_id, user_id, emoji)
                 VALUES (:tenant, :message, :user, :emoji)
                 ON CONFLICT (message_id, user_id, emoji) DO NOTHING',
                ['tenant' => $tenantId, 'message' => $messageId, 'user' => $userId, 'emoji' => $emoji],
            );
        }

        return $this->reactionsFor($tenantId, [$messageId])[$messageId] ?? [];
    }

    public function reactionsFor(string $tenantId, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        // Grouped in the query, because that is how they are drawn — "👍 3"
        // with the names behind it, not a row per reaction for the client to
        // group itself.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT r.message_id, r.emoji, COUNT(*) AS n,
                    ARRAY_AGG(r.user_id::text) AS user_ids,
                    ARRAY_AGG(TRIM(CONCAT(u.first_name, ' ', u.last_name))) AS names
             FROM message_reactions r
             LEFT JOIN users u ON u.id = r.user_id
             WHERE r.tenant_id = :tenant AND r.message_id IN (:ids)
             GROUP BY r.message_id, r.emoji
             ORDER BY n DESC, r.emoji",
            ['tenant' => $tenantId, 'ids' => array_values(array_unique($messageIds))],
            ['ids' => ArrayParameterType::STRING],
        );

        $byMessage = [];

        foreach ($rows as $row) {
            $byMessage[(string) $row['message_id']][] = [
                'emoji'   => (string) $row['emoji'],
                'count'   => (int) $row['n'],
                'userIds' => $this->fromArrayLiteral($row['user_ids']),
                'names'   => $this->fromArrayLiteral($row['names']),
            ];
        }

        return $byMessage;
    }

    // --- drafts --------------------------------------------------------------

    public function saveDraft(
        string $tenantId,
        string $conversationId,
        string $userId,
        string $content,
        ?string $replyToId,
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO message_drafts (conversation_id, user_id, tenant_id, content, reply_to_id)
             VALUES (:conversation, :user, :tenant, :content, :reply)
             ON CONFLICT (conversation_id, user_id) DO UPDATE SET
                 content     = EXCLUDED.content,
                 reply_to_id = EXCLUDED.reply_to_id,
                 updated_at  = NOW()',
            [
                'conversation' => $conversationId,
                'user'         => $userId,
                'tenant'       => $tenantId,
                'content'      => $content,
                'reply'        => $replyToId,
            ],
        );
    }

    public function draft(string $tenantId, string $conversationId, string $userId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT content, reply_to_id, updated_at FROM message_drafts
             WHERE tenant_id = :tenant AND conversation_id = :conversation AND user_id = :user',
            ['tenant' => $tenantId, 'conversation' => $conversationId, 'user' => $userId],
        );

        if ($row === false) {
            return null;
        }

        return [
            'content'   => (string) $row['content'],
            'replyToId' => $this->text($row['reply_to_id']),
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    public function deleteDraft(string $tenantId, string $conversationId, string $userId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM message_drafts
             WHERE tenant_id = :tenant AND conversation_id = :conversation AND user_id = :user',
            ['tenant' => $tenantId, 'conversation' => $conversationId, 'user' => $userId],
        );
    }

    // --- pinned and saved ----------------------------------------------------

    public function setPinned(
        string $tenantId,
        string $messageId,
        bool $pinned,
        ?string $by,
    ): ?Message {
        $affected = $this->connection->executeStatement(
            $pinned
                ? 'UPDATE messages SET pinned_at = NOW(), pinned_by = :by
                   WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL'
                : 'UPDATE messages SET pinned_at = NULL, pinned_by = NULL
                   WHERE id = :id AND tenant_id = :tenant',
            $pinned
                ? ['id' => $messageId, 'tenant' => $tenantId, 'by' => $by]
                : ['id' => $messageId, 'tenant' => $tenantId],
        );

        return $affected > 0 ? $this->findMessage($tenantId, $messageId) : null;
    }

    public function pinnedIn(string $tenantId, string $conversationId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM messages m
                 LEFT JOIN users u ON u.id = m.sender_id
                 LEFT JOIN messages r ON r.id = m.reply_to_id
                 LEFT JOIN users ru ON ru.id = r.sender_id
                 WHERE m.tenant_id = :tenant AND m.conversation_id = :conversation
                   AND m.pinned_at IS NOT NULL AND m.deleted_at IS NULL
                 ORDER BY m.pinned_at DESC',
                $this->messageColumns(),
            ),
            ['tenant' => $tenantId, 'conversation' => $conversationId],
        );

        return array_map(fn (array $row): Message => $this->hydrateMessage($row), $rows);
    }

    public function saveMessage(
        string $tenantId,
        string $messageId,
        string $userId,
        ?string $note,
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO saved_messages (tenant_id, message_id, user_id, note)
             VALUES (:tenant, :message, :user, :note)
             ON CONFLICT (user_id, message_id) DO UPDATE SET note = EXCLUDED.note',
            ['tenant' => $tenantId, 'message' => $messageId, 'user' => $userId, 'note' => $note],
        );
    }

    public function unsaveMessage(string $tenantId, string $messageId, string $userId): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM saved_messages
             WHERE tenant_id = :tenant AND message_id = :message AND user_id = :user',
            ['tenant' => $tenantId, 'message' => $messageId, 'user' => $userId],
        ) > 0;
    }

    public function savedBy(string $tenantId, string $userId, int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT %s, s.note, s.created_at AS saved_at,
                        c.name AS conversation_name, c.kind AS conversation_kind
                 FROM saved_messages s
                 JOIN messages m ON m.id = s.message_id
                 JOIN conversations c ON c.id = m.conversation_id
                 LEFT JOIN users u ON u.id = m.sender_id
                 LEFT JOIN messages r ON r.id = m.reply_to_id
                 LEFT JOIN users ru ON ru.id = r.sender_id
                 WHERE s.tenant_id = :tenant AND s.user_id = :user
                 ORDER BY s.created_at DESC
                 LIMIT :limit OFFSET :offset",
                $this->messageColumns(),
            ),
            ['tenant' => $tenantId, 'user' => $userId, 'limit' => $limit, 'offset' => $offset],
        );

        return array_map(
            fn (array $row): array => [
                ...$this->hydrateMessage($row)->toArray(),
                'note'             => $this->text($row['note']),
                'savedAt'          => (string) $row['saved_at'],
                'conversationName' => $this->text($row['conversation_name']),
                'conversationKind' => (string) $row['conversation_kind'],
            ],
            $rows,
        );
    }

    // --- searching -----------------------------------------------------------

    public function search(
        string $tenantId,
        string $userId,
        string $phrase,
        ?string $conversationId,
        int $limit,
    ): array {
        $where = [
            'm.tenant_id = :tenant',
            'm.deleted_at IS NULL',
            'm.content ILIKE :phrase',
            // Only conversations this person is in. A tenant-wide search
            // would hand somebody every private conversation containing the
            // word they typed.
            'EXISTS (
                 SELECT 1 FROM conversation_participants p
                 WHERE p.conversation_id = m.conversation_id
                   AND p.user_id = :user AND p.left_at IS NULL
             )',
        ];

        $params = [
            'tenant' => $tenantId,
            'user'   => $userId,
            'phrase' => '%' . trim($phrase) . '%',
            'limit'  => $limit,
        ];

        if ($conversationId !== null && $conversationId !== '') {
            $where[] = 'm.conversation_id = :conversation';
            $params['conversation'] = $conversationId;
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM messages m
                 LEFT JOIN users u ON u.id = m.sender_id
                 LEFT JOIN messages r ON r.id = m.reply_to_id
                 LEFT JOIN users ru ON ru.id = r.sender_id
                 WHERE %s
                 ORDER BY m.created_at DESC
                 LIMIT :limit',
                $this->messageColumns(),
                implode(' AND ', $where),
            ),
            $params,
        );

        return array_map(fn (array $row): Message => $this->hydrateMessage($row), $rows);
    }

    // --- blocking ------------------------------------------------------------

    public function block(string $tenantId, string $userId, string $blockedId, ?string $reason): void
    {
        $this->connection->executeStatement(
            'INSERT INTO blocked_users (tenant_id, user_id, blocked_id, reason)
             VALUES (:tenant, :user, :blocked, :reason)
             ON CONFLICT (user_id, blocked_id) DO UPDATE SET reason = EXCLUDED.reason',
            ['tenant' => $tenantId, 'user' => $userId, 'blocked' => $blockedId, 'reason' => $reason],
        );
    }

    public function unblock(string $tenantId, string $userId, string $blockedId): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM blocked_users
             WHERE tenant_id = :tenant AND user_id = :user AND blocked_id = :blocked',
            ['tenant' => $tenantId, 'user' => $userId, 'blocked' => $blockedId],
        ) > 0;
    }

    public function blockedBy(string $tenantId, string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT b.blocked_id, b.reason, b.created_at,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS name, u.email
             FROM blocked_users b
             LEFT JOIN users u ON u.id = b.blocked_id
             WHERE b.tenant_id = :tenant AND b.user_id = :user
             ORDER BY b.created_at DESC",
            ['tenant' => $tenantId, 'user' => $userId],
        );

        return array_map(
            fn (array $row): array => [
                'userId'    => (string) $row['blocked_id'],
                'name'      => $this->text($row['name']),
                'email'     => $this->text($row['email']),
                'reason'    => $this->text($row['reason']),
                'blockedAt' => (string) $row['created_at'],
            ],
            $rows,
        );
    }

    public function isBlockedBetween(string $tenantId, string $a, string $b): bool
    {
        // Either direction: if A blocked B, B must not be able to open a
        // conversation with A either, or blocking is only a mute.
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM blocked_users
             WHERE tenant_id = :tenant
               AND ((user_id = :a AND blocked_id = :b) OR (user_id = :b AND blocked_id = :a))',
            ['tenant' => $tenantId, 'a' => $a, 'b' => $b],
        ) > 0;
    }

    // --- preferences ---------------------------------------------------------

    public function preferences(string $tenantId, string $userId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT notify_on_message, notify_on_mention, email_digest,
                    direct_messages_from_anyone, show_read_receipts
             FROM messaging_preferences
             WHERE tenant_id = :tenant AND user_id = :user',
            ['tenant' => $tenantId, 'user' => $userId],
        );

        // No row means the defaults, which is why there is no row written when
        // somebody has never opened the screen.
        if ($row === false) {
            return [
                'notifyOnMessage'         => true,
                'notifyOnMention'         => true,
                'emailDigest'             => false,
                'directMessagesFromAnyone' => true,
                'showReadReceipts'        => true,
            ];
        }

        return [
            'notifyOnMessage'          => (bool) $row['notify_on_message'],
            'notifyOnMention'          => (bool) $row['notify_on_mention'],
            'emailDigest'              => (bool) $row['email_digest'],
            'directMessagesFromAnyone' => (bool) $row['direct_messages_from_anyone'],
            'showReadReceipts'         => (bool) $row['show_read_receipts'],
        ];
    }

    public function savePreferences(string $tenantId, string $userId, array $changes): array
    {
        $current = $this->preferences($tenantId, $userId);
        $merged = [...$current, ...array_intersect_key($changes, $current)];

        $this->connection->executeStatement(
            'INSERT INTO messaging_preferences
                 (user_id, tenant_id, notify_on_message, notify_on_mention, email_digest,
                  direct_messages_from_anyone, show_read_receipts)
             VALUES (:user, :tenant, :message, :mention, :digest, :dms, :receipts)
             ON CONFLICT (user_id) DO UPDATE SET
                 notify_on_message           = EXCLUDED.notify_on_message,
                 notify_on_mention           = EXCLUDED.notify_on_mention,
                 email_digest                = EXCLUDED.email_digest,
                 direct_messages_from_anyone = EXCLUDED.direct_messages_from_anyone,
                 show_read_receipts          = EXCLUDED.show_read_receipts,
                 updated_at                  = NOW()',
            [
                'user'     => $userId,
                'tenant'   => $tenantId,
                'message'  => (bool) $merged['notifyOnMessage'],
                'mention'  => (bool) $merged['notifyOnMention'],
                'digest'   => (bool) $merged['emailDigest'],
                'dms'      => (bool) $merged['directMessagesFromAnyone'],
                'receipts' => (bool) $merged['showReadReceipts'],
            ],
            [
                'message'  => ParameterType::BOOLEAN,
                'mention'  => ParameterType::BOOLEAN,
                'digest'   => ParameterType::BOOLEAN,
                'dms'      => ParameterType::BOOLEAN,
                'receipts' => ParameterType::BOOLEAN,
            ],
        );

        return $merged;
    }

    /**
     * A PostgreSQL array literal as a PHP list.
     *
     * `ARRAY_AGG` comes back as `{a,b,c}` over the wire rather than as an
     * array, and names containing commas would break a naive split — so the
     * quoted form is handled too.
     *
     * @return list<string>
     */
    private function fromArrayLiteral(mixed $value): array
    {
        if (!is_string($value) || $value === '{}') {
            return [];
        }

        $inner = trim($value, '{}');

        if ($inner === '') {
            return [];
        }

        $parts = str_getcsv($inner, ',', '"', '\\');

        return array_values(array_map(
            static fn (?string $p): string => trim((string) $p),
            $parts,
        ));
    }
}
