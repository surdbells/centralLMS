<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Messaging\Conversation;
use Central\Api\Domain\Messaging\ConversationKind;
use Central\Api\Domain\Messaging\Message;
use Central\Api\Infrastructure\Persistence\DbalMessagingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Conversations against a real PostgreSQL.
 *
 * Two things need the database: that a pair cannot end up with two direct
 * conversations, and that the unread count is a query rather than a column
 * every send has to fan out an UPDATE to maintain.
 */
#[CoversClass(DbalMessagingRepository::class)]
final class DbalMessagingRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalMessagingRepository $messaging;

    private string $tenantA;
    private string $tenantB;
    private string $ada;
    private string $grace;
    private string $sam;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->messaging = new DbalMessagingRepository(self::$connection);

        $this->tenantA = $this->createTenant('msg-a');
        $this->tenantB = $this->createTenant('msg-b');
        $this->ada     = $this->createUser($this->tenantA, 'Ada');
        $this->grace   = $this->createUser($this->tenantA, 'Grace');
        $this->sam     = $this->createUser($this->tenantA, 'Sam');
    }

    protected function tearDown(): void
    {
        if (self::$connection === null) {
            return;
        }

        foreach ([$this->tenantA ?? null, $this->tenantB ?? null] as $tenantId) {
            if ($tenantId === null) {
                continue;
            }

            foreach (['messages', 'conversation_participants', 'conversations', 'users'] as $table) {
                self::$connection->executeStatement(
                    sprintf('DELETE FROM %s WHERE tenant_id = :id', $table),
                    ['id' => $tenantId],
                );
            }

            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    // --- direct conversations ---------------------------------------------

    #[Test]
    public function a_pair_gets_one_conversation_whichever_way_round_it_starts(): void
    {
        // The legacy had four direct conversations and nothing stopping a
        // fifth between a pair that already had one, which splits a
        // conversation in half and hides the older one.
        $first  = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);
        $second = $this->messaging->directWith($this->tenantA, $this->grace, $this->ada);

        self::assertSame($first->id, $second->id);
        self::assertSame(ConversationKind::Direct, $first->kind);
        self::assertCount(2, $first->participants);
    }

    #[Test]
    public function different_pairs_get_different_conversations(): void
    {
        $withGrace = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);
        $withSam   = $this->messaging->directWith($this->tenantA, $this->ada, $this->sam);

        self::assertNotSame($withGrace->id, $withSam->id);
    }

    #[Test]
    public function a_direct_conversation_has_no_name_of_its_own(): void
    {
        $conversation = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);

        self::assertNull($conversation->name);
        self::assertSame('Grace Tester', $conversation->titleFor($this->ada));
        self::assertSame('Ada Tester', $conversation->titleFor($this->grace));
    }

    // --- groups -----------------------------------------------------------

    #[Test]
    public function whoever_starts_a_group_owns_it(): void
    {
        $group = $this->messaging->startGroup(
            $this->tenantA,
            $this->ada,
            ConversationKind::Group,
            'Management',
            null,
            [$this->grace],
        );

        self::assertSame('owner', $group->viewer?->role);
        self::assertCount(2, $group->participants);
    }

    #[Test]
    public function the_creator_is_not_added_twice(): void
    {
        $group = $this->messaging->startGroup(
            $this->tenantA,
            $this->ada,
            ConversationKind::Group,
            'Management',
            null,
            [$this->ada, $this->grace, $this->ada],
        );

        self::assertCount(2, $group->participants);
    }

    #[Test]
    public function somebody_from_another_tenant_cannot_be_added(): void
    {
        $outsider = $this->createUser($this->tenantB, 'Outsider');

        $group = $this->messaging->startGroup(
            $this->tenantA,
            $this->ada,
            ConversationKind::Group,
            'Management',
            null,
            [$this->grace, $outsider],
        );

        self::assertCount(2, $group->participants);
        self::assertNull($this->messaging->find($this->tenantA, $outsider, (string) $group->id));
    }

    #[Test]
    public function somebody_who_left_can_be_brought_back(): void
    {
        $group = $this->messaging->startGroup(
            $this->tenantA,
            $this->ada,
            ConversationKind::Group,
            'Management',
            null,
            [$this->grace],
        );

        self::assertTrue($this->messaging->removeParticipant($this->tenantA, (string) $group->id, $this->grace));
        self::assertNull($this->messaging->find($this->tenantA, $this->grace, (string) $group->id));

        // Re-adding restores the row rather than failing on the primary key.
        self::assertSame(1, $this->messaging->addParticipants(
            $this->tenantA,
            (string) $group->id,
            [$this->grace],
            $this->ada,
        ));
        self::assertNotNull($this->messaging->find($this->tenantA, $this->grace, (string) $group->id));
    }

    #[Test]
    public function leaving_keeps_the_row_so_the_history_still_reads(): void
    {
        $group = $this->messaging->startGroup(
            $this->tenantA,
            $this->ada,
            ConversationKind::Group,
            'Management',
            null,
            [$this->grace],
        );

        $this->send($group, $this->grace, 'I was here');
        $this->messaging->removeParticipant($this->tenantA, (string) $group->id, $this->grace);

        $messages = $this->messaging->messages($this->tenantA, (string) $group->id, 1, 25);

        self::assertSame('Grace Tester', $messages['items'][0]->senderName);

        $seen = $this->messaging->find($this->tenantA, $this->ada, (string) $group->id);

        self::assertNotNull($seen);
        self::assertCount(2, $seen->participants);
        self::assertFalse($seen->includes($this->grace));
    }

    #[Test]
    public function a_group_can_be_renamed_and_a_direct_conversation_cannot(): void
    {
        $group = $this->messaging->startGroup(
            $this->tenantA,
            $this->ada,
            ConversationKind::Group,
            'Management',
            null,
            [],
        );

        self::assertSame('Leadership', $this->messaging->rename(
            $this->tenantA,
            (string) $group->id,
            'Leadership',
            null,
        )?->name);

        $direct = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);

        self::assertNull($this->messaging->rename($this->tenantA, (string) $direct->id, 'Nope', null));
    }

    // --- privacy ----------------------------------------------------------

    #[Test]
    public function a_conversation_is_invisible_to_anybody_not_in_it(): void
    {
        // Not 403 but nothing at all: saying a conversation exists but is not
        // yours says who is talking.
        $direct = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);

        self::assertNotNull($this->messaging->find($this->tenantA, $this->ada, (string) $direct->id));
        self::assertNull($this->messaging->find($this->tenantA, $this->sam, (string) $direct->id));
        self::assertNull($this->messaging->find($this->tenantB, $this->ada, (string) $direct->id));
    }

    #[Test]
    public function the_inbox_holds_only_the_callers_own_conversations(): void
    {
        $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);
        $this->messaging->directWith($this->tenantA, $this->grace, $this->sam);

        self::assertSame(1, $this->messaging->inbox($this->tenantA, $this->ada, false, 1, 25)['total']);
        self::assertSame(2, $this->messaging->inbox($this->tenantA, $this->grace, false, 1, 25)['total']);
    }

    // --- unread -----------------------------------------------------------

    #[Test]
    public function unread_counts_what_arrived_since_the_reader_last_looked(): void
    {
        $direct = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);

        $this->send($direct, $this->ada, 'Good morning');
        $this->send($direct, $this->ada, 'How are you');

        self::assertSame(2, $this->unreadFor($this->grace, $direct));
        // Your own messages are not unread to you.
        self::assertSame(0, $this->unreadFor($this->ada, $direct));

        $this->messaging->markRead($this->tenantA, (string) $direct->id, $this->grace);

        self::assertSame(0, $this->unreadFor($this->grace, $direct));
    }

    #[Test]
    public function a_message_after_reading_is_unread_again(): void
    {
        $direct = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);

        $this->send($direct, $this->ada, 'Good morning');
        $this->messaging->markRead($this->tenantA, (string) $direct->id, $this->grace);
        $this->send($direct, $this->ada, 'Still there?');

        self::assertSame(1, $this->unreadFor($this->grace, $direct));
    }

    #[Test]
    public function a_deleted_message_is_not_unread(): void
    {
        $direct  = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);
        $message = $this->send($direct, $this->ada, 'Never mind');

        self::assertSame(1, $this->unreadFor($this->grace, $direct));

        $this->messaging->deleteMessage($this->tenantA, (string) $message->id, $this->ada);

        self::assertSame(0, $this->unreadFor($this->grace, $direct));
    }

    #[Test]
    public function the_total_is_across_every_conversation(): void
    {
        $withAda = $this->messaging->directWith($this->tenantA, $this->grace, $this->ada);
        $withSam = $this->messaging->directWith($this->tenantA, $this->grace, $this->sam);

        $this->send($withAda, $this->ada, 'One');
        $this->send($withAda, $this->ada, 'Two');
        $this->send($withSam, $this->sam, 'Three');

        self::assertSame(3, $this->messaging->unreadTotal($this->tenantA, $this->grace));
        self::assertSame(0, $this->messaging->unreadTotal($this->tenantA, $this->ada));
    }

    #[Test]
    public function an_archived_conversation_does_not_add_to_the_badge(): void
    {
        $direct = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);
        $this->send($direct, $this->ada, 'Good morning');

        self::assertSame(1, $this->messaging->unreadTotal($this->tenantA, $this->grace));

        $this->messaging->setArchived($this->tenantA, (string) $direct->id, true);

        self::assertSame(0, $this->messaging->unreadTotal($this->tenantA, $this->grace));
    }

    // --- messages ---------------------------------------------------------

    #[Test]
    public function the_message_count_is_counted(): void
    {
        // `conversations.message_count` was a column every send had to update.
        $direct = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);

        $this->send($direct, $this->ada, 'One');
        $this->send($direct, $this->grace, 'Two');

        self::assertSame(2, $this->messaging->find($this->tenantA, $this->ada, (string) $direct->id)?->messageCount);
    }

    #[Test]
    public function the_newest_message_is_looked_up_rather_than_stored(): void
    {
        // As was `last_message_id`.
        $direct = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);

        $this->send($direct, $this->ada, 'One');
        $latest = $this->send($direct, $this->grace, 'Two');

        $inbox = $this->messaging->inbox($this->tenantA, $this->ada, false, 1, 25);

        self::assertSame($latest->id, $inbox['items'][0]->lastMessage?->id);
        self::assertSame('Two', $inbox['items'][0]->lastMessage?->content);
    }

    #[Test]
    public function a_conversation_with_nothing_in_it_has_no_last_message(): void
    {
        $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);

        $inbox = $this->messaging->inbox($this->tenantA, $this->ada, false, 1, 25);

        self::assertSame(1, $inbox['total']);
        self::assertNull($inbox['items'][0]->lastMessage);
        self::assertSame(0, $inbox['items'][0]->messageCount);
    }

    #[Test]
    public function the_inbox_is_ordered_by_what_moved_most_recently(): void
    {
        $withGrace = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);
        $withSam   = $this->messaging->directWith($this->tenantA, $this->ada, $this->sam);

        $this->send($withGrace, $this->grace, 'First');
        $this->send($withSam, $this->sam, 'Second');

        $inbox = $this->messaging->inbox($this->tenantA, $this->ada, false, 1, 25);

        self::assertSame($withSam->id, $inbox['items'][0]->id);

        $this->send($withGrace, $this->grace, 'Third');

        $after = $this->messaging->inbox($this->tenantA, $this->ada, false, 1, 25);

        self::assertSame($withGrace->id, $after['items'][0]->id);
    }

    #[Test]
    public function a_pinned_conversation_comes_first_whatever_moved(): void
    {
        $withGrace = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);
        $withSam   = $this->messaging->directWith($this->tenantA, $this->ada, $this->sam);

        $this->send($withSam, $this->sam, 'Newest');
        $this->messaging->setViewState($this->tenantA, (string) $withGrace->id, $this->ada, null, true);

        $inbox = $this->messaging->inbox($this->tenantA, $this->ada, false, 1, 25);

        self::assertSame($withGrace->id, $inbox['items'][0]->id);
        // And pinning is one person's view of it, not everybody's.
        $theirs = $this->messaging->inbox($this->tenantA, $this->grace, false, 1, 25);
        self::assertFalse($theirs['items'][0]->viewer?->isPinned);
    }

    #[Test]
    public function only_the_author_may_edit(): void
    {
        $direct  = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);
        $message = $this->send($direct, $this->ada, 'Good morning');

        self::assertNull($this->messaging->edit($this->tenantA, (string) $message->id, $this->grace, 'Mine now'));

        $edited = $this->messaging->edit($this->tenantA, (string) $message->id, $this->ada, 'Good afternoon');

        self::assertSame('Good afternoon', $edited?->content);
        self::assertTrue($edited->isEdited());
    }

    #[Test]
    public function a_deleted_message_keeps_its_place(): void
    {
        $direct  = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);
        $message = $this->send($direct, $this->ada, 'Something regrettable');

        self::assertTrue($this->messaging->deleteMessage($this->tenantA, (string) $message->id, $this->ada));
        // Twice is once.
        self::assertFalse($this->messaging->deleteMessage($this->tenantA, (string) $message->id, $this->ada));

        $found = $this->messaging->findMessage($this->tenantA, (string) $message->id);

        self::assertTrue($found?->isDeleted());
        self::assertSame('This message was deleted.', $found->visibleContent());
        // Editing a deleted message is not a way to bring it back.
        self::assertNull($this->messaging->edit($this->tenantA, (string) $message->id, $this->ada, 'Actually'));
    }

    #[Test]
    public function a_reply_to_a_deleted_message_shows_the_placeholder(): void
    {
        // Rather than the words that were taken down.
        $direct   = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);
        $original = $this->send($direct, $this->ada, 'Something regrettable');
        $this->send($direct, $this->grace, 'Quite', (string) $original->id);

        $this->messaging->deleteMessage($this->tenantA, (string) $original->id, $this->ada);

        $messages = $this->messaging->messages($this->tenantA, (string) $direct->id, 1, 25);
        $reply    = $messages['items'][0];

        self::assertSame('Quite', $reply->content);
        self::assertSame('This message was deleted.', $reply->replyToContent);
    }

    #[Test]
    public function a_reply_carries_its_original(): void
    {
        $direct   = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);
        $original = $this->send($direct, $this->ada, 'Good morning');
        $reply    = $this->send($direct, $this->grace, 'Morning', (string) $original->id);

        $found = $this->messaging->findMessage($this->tenantA, (string) $reply->id);

        self::assertSame('Good morning', $found?->replyToContent);
        self::assertSame('Ada Tester', $found->replyToSenderName);
    }

    #[Test]
    public function messages_can_be_searched(): void
    {
        $direct = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);

        $this->send($direct, $this->ada, 'The fire drill is on Tuesday');
        $this->send($direct, $this->ada, 'Never mind');

        self::assertSame(
            1,
            $this->messaging->messages($this->tenantA, (string) $direct->id, 1, 25, 'fire drill')['total'],
        );
        self::assertSame(
            0,
            $this->messaging->messages($this->tenantA, (string) $direct->id, 1, 25, 'nothing here')['total'],
        );
    }

    #[Test]
    public function a_deleted_message_is_not_found_by_searching_its_words(): void
    {
        $direct  = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);
        $message = $this->send($direct, $this->ada, 'The fire drill is on Tuesday');

        $this->messaging->deleteMessage($this->tenantA, (string) $message->id, $this->ada);

        self::assertSame(
            0,
            $this->messaging->messages($this->tenantA, (string) $direct->id, 1, 25, 'fire drill')['total'],
        );
    }

    #[Test]
    public function archiving_moves_a_conversation_out_of_the_inbox(): void
    {
        $direct = $this->messaging->directWith($this->tenantA, $this->ada, $this->grace);

        $this->messaging->setArchived($this->tenantA, (string) $direct->id, true);

        self::assertSame(0, $this->messaging->inbox($this->tenantA, $this->ada, false, 1, 25)['total']);
        self::assertSame(1, $this->messaging->inbox($this->tenantA, $this->ada, true, 1, 25)['total']);

        $this->messaging->setArchived($this->tenantA, (string) $direct->id, false);

        self::assertSame(1, $this->messaging->inbox($this->tenantA, $this->ada, false, 1, 25)['total']);
    }

    // --- helpers ----------------------------------------------------------

    private function send(
        Conversation $conversation,
        string $senderId,
        string $content,
        ?string $replyTo = null,
    ): Message {
        // A short pause so `created_at` orders deterministically: two messages
        // in the same microsecond would leave the newest ambiguous.
        usleep(2000);

        return $this->messaging->send(new Message(
            id: null,
            tenantId: $this->tenantA,
            conversationId: (string) $conversation->id,
            content: $content,
            senderId: $senderId,
            replyToId: $replyTo,
        ));
    }

    private function unreadFor(string $userId, Conversation $conversation): int
    {
        return (int) $this->messaging->find($this->tenantA, $userId, (string) $conversation->id)?->unreadCount;
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, 'active')",
            ['id' => $id, 'name' => 'Test ' . $prefix, 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $tenantId, string $first): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status)
             VALUES (:id, :t, :e, 'x', :f, 'Tester', 'active')",
            [
                'id' => $id,
                't'  => $tenantId,
                'e'  => strtolower($first) . '-' . substr($id, 0, 8) . '@msg.test',
                'f'  => $first,
            ],
        );

        return $id;
    }

    private static function connect(): ?Connection
    {
        try {
            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port'     => (int) ($_ENV['DB_PORT'] ?? 5442),
                'dbname'   => $_ENV['DB_NAME'] ?? 'central_lms',
                'user'     => $_ENV['DB_USER'] ?? 'central_lms',
                'password' => $_ENV['DB_PASSWORD'] ?? 'central_lms',
            ]);

            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }
}
