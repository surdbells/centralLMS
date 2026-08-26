<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Messaging;

use Central\Api\Domain\Messaging\Conversation;
use Central\Api\Domain\Messaging\ConversationKind;
use Central\Api\Domain\Messaging\DirectKey;
use Central\Api\Domain\Messaging\Message;
use Central\Api\Domain\Messaging\Participant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rules of a conversation, without a database.
 *
 * The one that earns its keep is {@see DirectKey}: it is why a pair cannot end
 * up with two conversations between them, which the legacy had nothing to stop.
 */
#[CoversClass(Conversation::class)]
#[CoversClass(Participant::class)]
#[CoversClass(Message::class)]
#[CoversClass(DirectKey::class)]
#[CoversClass(ConversationKind::class)]
final class ConversationTest extends TestCase
{
    // --- the pair key -----------------------------------------------------

    #[Test]
    public function the_same_pair_produces_the_same_key_either_way_round(): void
    {
        // Ada writing to Grace and Grace writing to Ada are the same
        // conversation, so the unique index catches the second attempt no
        // matter who starts it.
        self::assertSame(
            DirectKey::between('ada', 'grace')->value,
            DirectKey::between('grace', 'ada')->value,
        );
    }

    #[Test]
    public function different_pairs_produce_different_keys(): void
    {
        self::assertNotSame(
            DirectKey::between('ada', 'grace')->value,
            DirectKey::between('ada', 'sam')->value,
        );
    }

    #[Test]
    public function a_conversation_with_oneself_is_recognised(): void
    {
        // Which is a notepad, and there is a notes module for that.
        self::assertTrue(DirectKey::isSelf('ada', 'ada'));
        self::assertTrue(DirectKey::isSelf('ada', ' ada '));
        self::assertFalse(DirectKey::isSelf('ada', 'grace'));
    }

    // --- what it is called ------------------------------------------------

    #[Test]
    public function a_direct_conversation_is_named_by_the_other_person(): void
    {
        // Which is why the name is not stored for one: it would be the wrong
        // name for one of the two people, and stale for both the moment
        // somebody is renamed.
        $conversation = $this->direct();

        self::assertSame('Grace Hopper', $conversation->titleFor('ada'));
        self::assertSame('Ada Lovelace', $conversation->titleFor('grace'));
    }

    #[Test]
    public function a_group_is_named_by_its_name(): void
    {
        self::assertSame('Management', $this->group()->titleFor('ada'));
        self::assertSame('Management', $this->group()->titleFor('grace'));
    }

    #[Test]
    public function a_direct_conversation_seen_by_a_stranger_falls_back(): void
    {
        // Reachable only through an administrative read-back, and it still has
        // to render.
        self::assertSame('Ada Lovelace', $this->direct()->titleFor(null));
    }

    #[Test]
    public function only_a_named_kind_carries_a_name(): void
    {
        self::assertFalse(ConversationKind::Direct->isNamed());
        self::assertTrue(ConversationKind::Group->isNamed());
        self::assertTrue(ConversationKind::Channel->isNamed());
    }

    #[Test]
    public function announcements_are_not_a_conversation_kind(): void
    {
        // They are `notification.send`, which the notification module already
        // does with delivery tracking and per-person preferences.
        self::assertNull(ConversationKind::tryFrom('announcement'));
    }

    // --- membership -------------------------------------------------------

    #[Test]
    public function somebody_who_has_left_is_no_longer_in_it(): void
    {
        $conversation = new Conversation(
            id: 'c-1',
            tenantId: 't-1',
            kind: ConversationKind::Group,
            name: 'Management',
            participants: [
                new Participant(userId: 'ada', role: 'owner', name: 'Ada Lovelace'),
                new Participant(
                    userId: 'grace',
                    name: 'Grace Hopper',
                    leftAt: new \DateTimeImmutable('2026-03-01'),
                ),
            ],
        );

        self::assertTrue($conversation->includes('ada'));
        self::assertFalse($conversation->includes('grace'));
        // But they are still listed, because their messages still carry their
        // name.
        self::assertCount(2, $conversation->participants);
    }

    #[Test]
    public function the_role_decides_what_somebody_may_do(): void
    {
        // The legacy declared six `can_*` booleans beside the role and every
        // one is zero on every row, including every owner's.
        $owner  = new Participant(userId: 'ada', role: 'owner');
        $admin  = new Participant(userId: 'grace', role: 'admin');
        $member = new Participant(userId: 'sam', role: 'member');

        self::assertTrue($owner->administers());
        self::assertTrue($admin->administers());
        self::assertFalse($member->administers());

        // Taking down what another person said is heavier than running the
        // membership.
        self::assertTrue($owner->moderates());
        self::assertFalse($admin->moderates());
    }

    // --- messages ---------------------------------------------------------

    #[Test]
    public function a_deleted_message_keeps_its_place_and_loses_its_words(): void
    {
        // A thread with a hole in it reads as data loss.
        $message = new Message(
            id: 'm-1',
            tenantId: 't-1',
            conversationId: 'c-1',
            content: 'Something regrettable',
            senderId: 'ada',
            deletedAt: new \DateTimeImmutable('2026-03-01'),
            deletedBy: 'ada',
        );

        self::assertTrue($message->isDeleted());
        self::assertSame('This message was deleted.', $message->visibleContent());
        self::assertSame('This message was deleted.', $message->toArray()['content']);
    }

    #[Test]
    public function only_the_author_may_edit_and_only_while_it_stands(): void
    {
        // Editing somebody else's words is not moderation.
        $message = new Message(
            id: 'm-1',
            tenantId: 't-1',
            conversationId: 'c-1',
            content: 'Good morning',
            senderId: 'ada',
        );

        self::assertTrue($message->isEditableBy('ada'));
        self::assertFalse($message->isEditableBy('grace'));

        $deleted = new Message(
            id: 'm-1',
            tenantId: 't-1',
            conversationId: 'c-1',
            content: 'Good morning',
            senderId: 'ada',
            deletedAt: new \DateTimeImmutable('2026-03-01'),
        );

        self::assertFalse($deleted->isEditableBy('ada'));
    }

    #[Test]
    public function a_system_message_has_no_author_and_cannot_be_edited(): void
    {
        $message = new Message(
            id: 'm-1',
            tenantId: 't-1',
            conversationId: 'c-1',
            content: 'Ada added Grace',
            kind: 'system',
        );

        self::assertTrue($message->isSystem());
        self::assertNull($message->senderId);
        self::assertFalse($message->isEditableBy('ada'));
    }

    #[Test]
    public function an_edited_message_says_so(): void
    {
        $message = new Message(
            id: 'm-1',
            tenantId: 't-1',
            conversationId: 'c-1',
            content: 'Good morning',
            senderId: 'ada',
            editedAt: new \DateTimeImmutable('2026-03-01'),
        );

        self::assertTrue($message->isEdited());
        self::assertTrue($message->toArray()['isEdited']);
    }

    #[Test]
    public function a_reply_carries_what_it_answers(): void
    {
        $message = new Message(
            id: 'm-2',
            tenantId: 't-1',
            conversationId: 'c-1',
            content: 'Morning',
            senderId: 'grace',
            replyToId: 'm-1',
            replyToContent: 'Good morning',
            replyToSenderName: 'Ada Lovelace',
        );

        $payload = $message->toArray();

        self::assertSame('m-1', $payload['replyTo']['id']);
        self::assertSame('Good morning', $payload['replyTo']['content']);
        self::assertSame('Ada Lovelace', $payload['replyTo']['senderName']);
    }

    #[Test]
    public function a_message_that_answers_nothing_has_no_reply_block(): void
    {
        $message = new Message(
            id: 'm-1',
            tenantId: 't-1',
            conversationId: 'c-1',
            content: 'Good morning',
            senderId: 'ada',
        );

        self::assertNull($message->toArray()['replyTo']);
    }

    // --- the payload ------------------------------------------------------

    #[Test]
    public function the_viewers_own_state_is_reported_beside_the_conversation(): void
    {
        $conversation = new Conversation(
            id: 'c-1',
            tenantId: 't-1',
            kind: ConversationKind::Group,
            name: 'Management',
            unreadCount: 3,
            viewer: new Participant(userId: 'ada', role: 'owner', isMuted: true, isPinned: true),
        );

        $payload = $conversation->toArray('ada');

        self::assertSame(3, $payload['unreadCount']);
        self::assertTrue($payload['isMuted']);
        self::assertTrue($payload['isPinned']);
        self::assertSame('owner', $payload['myRole']);
    }

    #[Test]
    public function a_conversation_read_without_a_viewer_still_renders(): void
    {
        $payload = $this->group()->toArray(null);

        self::assertFalse($payload['isMuted']);
        self::assertFalse($payload['isPinned']);
        self::assertNull($payload['myRole']);
    }

    private function direct(): Conversation
    {
        return new Conversation(
            id: 'c-1',
            tenantId: 't-1',
            kind: ConversationKind::Direct,
            participants: [
                new Participant(userId: 'ada', name: 'Ada Lovelace'),
                new Participant(userId: 'grace', name: 'Grace Hopper'),
            ],
        );
    }

    private function group(): Conversation
    {
        return new Conversation(
            id: 'c-2',
            tenantId: 't-1',
            kind: ConversationKind::Group,
            name: 'Management',
            participants: [new Participant(userId: 'ada', role: 'owner', name: 'Ada Lovelace')],
        );
    }
}
