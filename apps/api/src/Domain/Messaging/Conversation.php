<?php

declare(strict_types=1);

namespace Central\Api\Domain\Messaging;

/**
 * A conversation, as it looks to one of the people in it.
 *
 * The unread count and the last message are counted and looked up rather than
 * stored. `conversations.message_count` and
 * `conversation_participants.unread_count` were columns every send had to fan
 * out an UPDATE to maintain — one write per participant, per message, to say
 * something the messages themselves already say.
 */
final readonly class Conversation
{
    /**
     * @param list<Participant> $participants
     */
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public ConversationKind $kind,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $createdBy = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $archivedAt = null,
        public array $participants = [],
        /** Derived, never stored. */
        public int $messageCount = 0,
        public int $unreadCount = 0,
        public ?Message $lastMessage = null,
        /** The viewer's own row, when the conversation was read for somebody. */
        public ?Participant $viewer = null,
    ) {
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /**
     * What to call it for the person reading.
     *
     * A direct conversation is named by whoever else is in it — which is why
     * the name is not stored for one: it would go stale the moment somebody is
     * renamed, and it would be the wrong name for one of the two people.
     */
    public function titleFor(?string $viewerId): string
    {
        if ($this->kind->isNamed()) {
            return (string) $this->name;
        }

        foreach ($this->participants as $participant) {
            if ($participant->userId !== $viewerId) {
                return $participant->name ?? 'Someone';
            }
        }

        return 'Just you';
    }

    public function includes(string $userId): bool
    {
        foreach ($this->participants as $participant) {
            if ($participant->userId === $userId && $participant->isActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?string $viewerId = null): array
    {
        return [
            'id'           => $this->id,
            'kind'         => $this->kind->value,
            'kindLabel'    => $this->kind->label(),
            'title'        => $this->titleFor($viewerId),
            'name'         => $this->name,
            'description'  => $this->description,
            'isArchived'   => $this->isArchived(),
            'createdBy'    => $this->createdBy,
            'createdAt'    => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'messageCount' => $this->messageCount,
            'unreadCount'  => $this->unreadCount,
            'isMuted'      => $this->viewer->isMuted ?? false,
            'isPinned'     => $this->viewer->isPinned ?? false,
            'myRole'       => $this->viewer?->role,
            'participants' => array_map(
                static fn (Participant $p): array => $p->toArray(),
                $this->participants,
            ),
            'lastMessage'  => $this->lastMessage?->toArray(),
        ];
    }
}
