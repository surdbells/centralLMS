<?php

declare(strict_types=1);

namespace Central\Api\Domain\Messaging;

/**
 * Storage for conversations and messages.
 *
 * Every method takes a tenant id **and** the id of the person asking, and there
 * is no method that reads a conversation without checking they are in it.
 * `Messaging` in the legacy took `user_id` from the request body like every
 * other endpoint — on a feature where that means reading other people's private
 * messages.
 */
interface MessagingRepository
{
    /**
     * The conversations this person is in, most recently active first.
     *
     * @return array{items: list<Conversation>, total: int}
     */
    public function inbox(string $tenantId, string $userId, bool $archived, int $page, int $perPage): array;

    /**
     * One conversation, or null if this person is not in it.
     *
     * The two cases are deliberately the same answer: telling somebody a
     * conversation exists but is not theirs is telling them who is talking.
     */
    public function find(string $tenantId, string $userId, string $id): ?Conversation;

    /**
     * The one direct conversation between two people, creating it if there is
     * none.
     *
     * Find-or-create rather than create: the unique index makes a second one
     * impossible, and racing two "start a chat" taps should produce one
     * conversation rather than an error.
     */
    public function directWith(string $tenantId, string $userId, string $otherId): Conversation;

    /**
     * Starts a group or a channel.
     *
     * @param list<string> $participantIds the creator is added as owner regardless
     */
    public function startGroup(
        string $tenantId,
        string $creatorId,
        ConversationKind $kind,
        string $name,
        ?string $description,
        array $participantIds,
    ): Conversation;

    public function rename(string $tenantId, string $id, string $name, ?string $description): ?Conversation;

    /**
     * @param  list<string> $userIds
     * @return int          how many were actually added
     */
    public function addParticipants(string $tenantId, string $conversationId, array $userIds, string $addedBy): int;

    /**
     * Marks somebody as having left, keeping the row.
     *
     * Their messages stay and still carry their name. Removing the row would
     * leave a conversation whose history refers to a person nobody can see.
     */
    public function removeParticipant(string $tenantId, string $conversationId, string $userId): bool;

    public function setArchived(string $tenantId, string $id, bool $archived): ?Conversation;

    /**
     * Per-person view state: muted, pinned.
     */
    public function setViewState(
        string $tenantId,
        string $conversationId,
        string $userId,
        ?bool $muted,
        ?bool $pinned,
    ): bool;

    // --- messages ---------------------------------------------------------

    /**
     * @return array{items: list<Message>, total: int}
     */
    public function messages(
        string $tenantId,
        string $conversationId,
        int $page,
        int $perPage,
        ?string $search = null,
    ): array;

    public function findMessage(string $tenantId, string $id): ?Message;

    public function send(Message $message): Message;

    public function edit(string $tenantId, string $id, string $senderId, string $content): ?Message;

    /**
     * Soft delete, keeping the row so replies still make sense.
     */
    public function deleteMessage(string $tenantId, string $id, string $deletedBy): bool;

    /**
     * Moves this person's read marker to now.
     */
    public function markRead(string $tenantId, string $conversationId, string $userId): bool;

    /**
     * How many messages this person has not read, across everything.
     */
    public function unreadTotal(string $tenantId, string $userId): int;
}
