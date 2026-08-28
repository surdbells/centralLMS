<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Infrastructure\Persistence\DbalMessagingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Reactions, drafts, pins, saved messages and blocking, against real
 * PostgreSQL.
 *
 * The things worth proving are the ones the type system cannot: that reacting
 * twice takes the reaction back rather than stacking it, that search does not
 * reach into conversations the searcher is not in, and that blocking works in
 * both directions.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalMessagingRepository::class)]
final class DbalMessagingExtrasTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalMessagingRepository $messaging;

    private string $tenantA;
    private string $tenantB;
    private string $alice;
    private string $bob;
    private string $carol;
    private string $conversationId;
    private string $messageId;

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
        $this->alice = $this->createUser($this->tenantA, 'alice');
        $this->bob = $this->createUser($this->tenantA, 'bob');
        $this->carol = $this->createUser($this->tenantA, 'carol');

        $this->conversationId = $this->createConversation([$this->alice, $this->bob]);
        $this->messageId = $this->createMessage($this->conversationId, $this->alice, 'The harness check.');
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

            self::$connection->executeStatement('DELETE FROM users WHERE tenant_id = :id', ['id' => $tenantId]);
            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    // --- reactions ------------------------------------------------------------

    #[Test]
    public function reacting_twice_with_the_same_emoji_takes_it_back(): void
    {
        $after = $this->messaging->toggleReaction($this->tenantA, $this->messageId, $this->alice, '👍');

        self::assertCount(1, $after);
        self::assertSame(1, $after[0]['count']);

        // The same gesture again is a correction, not a second reaction.
        $again = $this->messaging->toggleReaction($this->tenantA, $this->messageId, $this->alice, '👍');

        self::assertSame([], $again);
    }

    #[Test]
    public function reactions_are_grouped_by_emoji_with_who_reacted(): void
    {
        $this->messaging->toggleReaction($this->tenantA, $this->messageId, $this->alice, '👍');
        $this->messaging->toggleReaction($this->tenantA, $this->messageId, $this->bob, '👍');
        $this->messaging->toggleReaction($this->tenantA, $this->messageId, $this->bob, '🎉');

        $found = $this->messaging->reactionsFor($this->tenantA, [$this->messageId])[$this->messageId];

        // Grouped as the screen draws them — "👍 2" ahead of "🎉 1".
        self::assertCount(2, $found);
        self::assertSame('👍', $found[0]['emoji']);
        self::assertSame(2, $found[0]['count']);
        self::assertCount(2, $found[0]['userIds']);
        self::assertContains($this->alice, $found[0]['userIds']);
    }

    // --- drafts ---------------------------------------------------------------

    #[Test]
    public function a_draft_is_one_per_conversation_and_replaces_itself(): void
    {
        $this->messaging->saveDraft($this->tenantA, $this->conversationId, $this->alice, 'First go', null);
        $this->messaging->saveDraft($this->tenantA, $this->conversationId, $this->alice, 'Second go', null);

        $draft = $this->messaging->draft($this->tenantA, $this->conversationId, $this->alice);

        self::assertNotNull($draft);
        self::assertSame('Second go', $draft['content']);
    }

    #[Test]
    public function two_people_keep_separate_drafts_in_one_conversation(): void
    {
        $this->messaging->saveDraft($this->tenantA, $this->conversationId, $this->alice, 'Alice typing', null);
        $this->messaging->saveDraft($this->tenantA, $this->conversationId, $this->bob, 'Bob typing', null);

        self::assertSame(
            'Alice typing',
            $this->messaging->draft($this->tenantA, $this->conversationId, $this->alice)['content'],
        );
        self::assertSame(
            'Bob typing',
            $this->messaging->draft($this->tenantA, $this->conversationId, $this->bob)['content'],
        );
    }

    // --- pinned and saved -----------------------------------------------------

    #[Test]
    public function pinning_is_for_everybody_in_the_conversation(): void
    {
        $this->messaging->setPinned($this->tenantA, $this->messageId, true, $this->alice);

        // Bob did not pin it and sees it pinned, which is the point.
        $pinned = $this->messaging->pinnedIn($this->tenantA, $this->conversationId);

        self::assertCount(1, $pinned);
        self::assertSame($this->messageId, $pinned[0]->id);

        $this->messaging->setPinned($this->tenantA, $this->messageId, false, $this->alice);

        self::assertSame([], $this->messaging->pinnedIn($this->tenantA, $this->conversationId));
    }

    #[Test]
    public function saving_is_the_readers_own_and_carries_their_note(): void
    {
        $this->messaging->saveMessage($this->tenantA, $this->messageId, $this->alice, 'Check this before the audit.');

        $mine = $this->messaging->savedBy($this->tenantA, $this->alice, 50, 0);
        $bobs = $this->messaging->savedBy($this->tenantA, $this->bob, 50, 0);

        self::assertCount(1, $mine);
        self::assertSame('Check this before the audit.', $mine[0]['note']);
        self::assertSame([], $bobs);
    }

    #[Test]
    public function saving_the_same_message_twice_updates_the_note(): void
    {
        $this->messaging->saveMessage($this->tenantA, $this->messageId, $this->alice, 'First note');
        $this->messaging->saveMessage($this->tenantA, $this->messageId, $this->alice, 'Better note');

        $mine = $this->messaging->savedBy($this->tenantA, $this->alice, 50, 0);

        self::assertCount(1, $mine);
        self::assertSame('Better note', $mine[0]['note']);
    }

    // --- searching ------------------------------------------------------------

    #[Test]
    public function search_does_not_reach_conversations_the_searcher_is_not_in(): void
    {
        // Bob and Carol talk; Alice is not in it.
        $theirs = $this->createConversation([$this->bob, $this->carol]);
        $this->createMessage($theirs, $this->bob, 'The harness is in the van.');

        $alicesResults = $this->messaging->search($this->tenantA, $this->alice, 'harness', null, 50);
        $bobsResults = $this->messaging->search($this->tenantA, $this->bob, 'harness', null, 50);

        // Alice sees only her own conversation's message, not theirs.
        self::assertCount(1, $alicesResults);
        self::assertSame($this->conversationId, $alicesResults[0]->conversationId);

        // Bob is in both.
        self::assertCount(2, $bobsResults);
    }

    #[Test]
    public function search_skips_deleted_messages(): void
    {
        self::$connection?->executeStatement(
            'UPDATE messages SET deleted_at = NOW() WHERE id = :id',
            ['id' => $this->messageId],
        );

        self::assertSame([], $this->messaging->search($this->tenantA, $this->alice, 'harness', null, 50));
    }

    // --- blocking -------------------------------------------------------------

    #[Test]
    public function blocking_holds_in_both_directions(): void
    {
        $this->messaging->block($this->tenantA, $this->alice, $this->bob, 'Persistent messages.');

        // If it only held one way it would be a mute, not a block.
        self::assertTrue($this->messaging->isBlockedBetween($this->tenantA, $this->alice, $this->bob));
        self::assertTrue($this->messaging->isBlockedBetween($this->tenantA, $this->bob, $this->alice));
        self::assertFalse($this->messaging->isBlockedBetween($this->tenantA, $this->alice, $this->carol));
    }

    #[Test]
    public function unblocking_lifts_it(): void
    {
        $this->messaging->block($this->tenantA, $this->alice, $this->bob, null);

        self::assertTrue($this->messaging->unblock($this->tenantA, $this->alice, $this->bob));
        self::assertFalse($this->messaging->isBlockedBetween($this->tenantA, $this->alice, $this->bob));
    }

    #[Test]
    public function a_blocked_list_names_who_and_why(): void
    {
        $this->messaging->block($this->tenantA, $this->alice, $this->bob, 'Persistent messages.');

        $blocked = $this->messaging->blockedBy($this->tenantA, $this->alice);

        self::assertCount(1, $blocked);
        self::assertSame($this->bob, $blocked[0]['userId']);
        self::assertSame('Persistent messages.', $blocked[0]['reason']);
    }

    // --- preferences ----------------------------------------------------------

    #[Test]
    public function preferences_default_without_a_row(): void
    {
        $defaults = $this->messaging->preferences($this->tenantA, $this->alice);

        // Somebody who has never opened the screen has no row, and gets the
        // defaults rather than a lot of falses.
        self::assertTrue($defaults['notifyOnMessage']);
        self::assertFalse($defaults['emailDigest']);
    }

    #[Test]
    public function saving_preferences_keeps_the_ones_not_mentioned(): void
    {
        $this->messaging->savePreferences($this->tenantA, $this->alice, ['emailDigest' => true]);
        $after = $this->messaging->savePreferences($this->tenantA, $this->alice, ['notifyOnMention' => false]);

        self::assertTrue($after['emailDigest']);
        self::assertFalse($after['notifyOnMention']);
        self::assertTrue($after['notifyOnMessage']);
    }

    // --- one tenant cannot see another's --------------------------------------

    #[Test]
    public function one_tenant_cannot_read_anothers_reactions_or_saves(): void
    {
        $this->messaging->toggleReaction($this->tenantA, $this->messageId, $this->alice, '👍');
        $this->messaging->saveMessage($this->tenantA, $this->messageId, $this->alice, null);

        self::assertSame([], $this->messaging->reactionsFor($this->tenantB, [$this->messageId]));
        self::assertSame([], $this->messaging->savedBy($this->tenantB, $this->alice, 50, 0));
    }

    // --- fixtures -------------------------------------------------------------

    /**
     * @param list<string> $participants
     */
    private function createConversation(array $participants): string
    {
        $id = (string) $this->db()->fetchOne(
            // A group conversation must be named: the schema says so, and a
            // nameless group is one nobody can refer to.
            "INSERT INTO conversations (tenant_id, kind, name, created_by)
             VALUES (:tenant, 'group', :name, :by) RETURNING id",
            ['tenant' => $this->tenantA, 'name' => 'Test thread', 'by' => $participants[0]],
        );

        foreach ($participants as $userId) {
            $this->db()->executeStatement(
                'INSERT INTO conversation_participants (conversation_id, user_id, tenant_id)
                 VALUES (:conversation, :user, :tenant)',
                ['conversation' => $id, 'user' => $userId, 'tenant' => $this->tenantA],
            );
        }

        return $id;
    }

    private function createMessage(string $conversationId, string $senderId, string $content): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO messages (tenant_id, conversation_id, sender_id, content, kind)
             VALUES (:tenant, :conversation, :sender, :content, 'text') RETURNING id",
            [
                'tenant'       => $this->tenantA,
                'conversation' => $conversationId,
                'sender'       => $senderId,
                'content'      => $content,
            ],
        );
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();

        $this->db()->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, 'active')",
            ['id' => $id, 'name' => $prefix . ' test tenant', 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $tenantId, string $who): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO users (tenant_id, email, first_name, last_name, status)
             VALUES (:tenant, :email, :first, 'Tester', 'active') RETURNING id",
            [
                'tenant' => $tenantId,
                'email'  => $who . '-' . substr(Uuid::uuid4()->toString(), 0, 8) . '@msg.test',
                'first'  => ucfirst($who),
            ],
        );
    }

    private function db(): Connection
    {
        return self::$connection ?? throw new \RuntimeException('No connection.');
    }

    private static function connect(): ?Connection
    {
        $root = dirname(__DIR__, 2);

        if (is_file($root . '/.env')) {
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }

        try {
            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port'     => (int) ($_ENV['DB_PORT'] ?? 5432),
                'dbname'   => $_ENV['DB_NAME'] ?? 'central_lms',
                'user'     => $_ENV['DB_USER'] ?? 'central_lms',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
            ], new \Doctrine\DBAL\Configuration());

            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }
}
