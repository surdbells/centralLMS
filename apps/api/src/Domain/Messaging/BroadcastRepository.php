<?php

declare(strict_types=1);

namespace Central\Api\Domain\Messaging;

interface BroadcastRepository
{
    /**
     * The broadcasts somebody has written, newest first.
     *
     * With their read and acknowledgement counts, which are counted here
     * rather than stored — all three move every time somebody opens one.
     *
     * @return array{items: list<Broadcast>, total: int}
     */
    public function authored(string $tenantId, ?string $authorId, int $page, int $perPage): array;

    public function find(string $tenantId, string $id): ?Broadcast;

    public function save(Broadcast $broadcast): Broadcast;

    /** Soft, so the acknowledgements already given keep their meaning. */
    public function delete(string $tenantId, string $id): bool;

    /**
     * Sends it: resolves the audience into recipients and stamps the time.
     *
     * One transaction, because a broadcast marked sent with no recipients is
     * an announcement nobody will ever see that looks delivered.
     *
     * @return int how many people it went to
     */
    public function send(string $tenantId, string $id): int;

    public function cancel(string $tenantId, string $id): bool;

    /**
     * What is due to go out now.
     *
     * For the sweep that sends scheduled announcements.
     *
     * @return list<Broadcast>
     */
    public function due(\DateTimeImmutable $now, int $limit): array;

    // --- what one person has waiting -----------------------------------------

    /**
     * The broadcasts sent to somebody, newest first.
     *
     * Expired ones are left out: an announcement past its date is not news,
     * and leaving it in a list nobody can clear is how that list stops being
     * read at all.
     *
     * @return list<array<string, mixed>>
     */
    public function forRecipient(string $tenantId, string $userId, bool $unreadOnly): array;

    /** @return array<string, mixed>|null */
    public function findForRecipient(string $tenantId, string $userId, string $id): ?array;

    public function markRead(string $tenantId, string $userId, string $id): bool;

    /**
     * Records that somebody has confirmed they read it.
     *
     * Marks it read at the same time if it was not already: acknowledging
     * something unread is not a state that means anything, and it is exactly
     * the state an audit would ask about.
     */
    public function acknowledge(string $tenantId, string $userId, string $id): bool;

    /** How many are waiting unread, for the badge. */
    public function unreadCount(string $tenantId, string $userId): int;
}
