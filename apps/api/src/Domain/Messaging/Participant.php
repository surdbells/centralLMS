<?php

declare(strict_types=1);

namespace Central\Api\Domain\Messaging;

/**
 * Somebody in a conversation.
 *
 * The role decides what they may do. The legacy declared six `can_*` booleans
 * beside it - can_post, can_add_members, can_remove_members, can_edit_settings,
 * can_pin_messages, can_delete_messages - and every one of them is zero on
 * every row, including every owner's. A permission model that says an owner
 * may not post is not a permission model anybody consulted.
 */
final readonly class Participant
{
    public function __construct(
        public string $userId,
        public string $role = 'member',
        public ?string $name = null,
        public ?string $email = null,
        public ?string $avatarUrl = null,
        public bool $isMuted = false,
        public bool $isPinned = false,
        public ?\DateTimeImmutable $lastReadAt = null,
        public ?\DateTimeImmutable $joinedAt = null,
        public ?\DateTimeImmutable $leftAt = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->leftAt === null;
    }

    /**
     * Whether they may add and remove people, and rename the conversation.
     */
    public function administers(): bool
    {
        return $this->role === 'owner' || $this->role === 'admin';
    }

    /**
     * Whether they may remove somebody else's message.
     *
     * Only an owner. An admin runs the membership; taking down what another
     * person said is a heavier act and belongs with whoever started the
     * conversation.
     */
    public function moderates(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'userId'    => $this->userId,
            'name'      => $this->name,
            'email'     => $this->email,
            'avatarUrl' => $this->avatarUrl,
            'role'      => $this->role,
            'isActive'  => $this->isActive(),
            'joinedAt'  => $this->joinedAt?->format(\DateTimeInterface::ATOM),
            'leftAt'    => $this->leftAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
