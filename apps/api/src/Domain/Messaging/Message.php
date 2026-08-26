<?php

declare(strict_types=1);

namespace Central\Api\Domain\Messaging;

/**
 * Something somebody said.
 *
 * One content column. The legacy had `content`, `content_html` and
 * `content_plain`, holding the same string character for character in all nine
 * of its messages, with nothing deciding which to read.
 */
final readonly class Message
{
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $conversationId,
        public string $content,
        public ?string $senderId = null,
        public string $kind = 'text',
        public ?string $replyToId = null,
        public ?\DateTimeImmutable $editedAt = null,
        public ?\DateTimeImmutable $deletedAt = null,
        public ?string $deletedBy = null,
        public ?\DateTimeImmutable $createdAt = null,
        /** Resolved for display. Read-only. */
        public ?string $senderName = null,
        public ?string $senderAvatarUrl = null,
        /** The message this replies to, one level deep. */
        public ?string $replyToContent = null,
        public ?string $replyToSenderName = null,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function isEdited(): bool
    {
        return $this->editedAt !== null;
    }

    public function isSystem(): bool
    {
        return $this->kind === 'system';
    }

    /**
     * What to show.
     *
     * A deleted message keeps its row and loses its words, so the replies to it
     * still make sense — a thread with a hole in it reads as data loss.
     */
    public function visibleContent(): string
    {
        return $this->isDeleted() ? 'This message was deleted.' : $this->content;
    }

    /**
     * Whether this person may edit it.
     *
     * Only the author, and only while it stands. Editing somebody else's words
     * is not moderation, it is forgery.
     */
    public function isEditableBy(string $userId): bool
    {
        return !$this->isDeleted() && !$this->isSystem() && $this->senderId === $userId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'conversationId'  => $this->conversationId,
            'content'         => $this->visibleContent(),
            'kind'            => $this->kind,
            'senderId'        => $this->senderId,
            'senderName'      => $this->senderName,
            'senderAvatarUrl' => $this->senderAvatarUrl,
            'replyToId'       => $this->replyToId,
            'replyTo'         => $this->replyToId === null ? null : [
                'id'         => $this->replyToId,
                'content'    => $this->replyToContent,
                'senderName' => $this->replyToSenderName,
            ],
            'isEdited'  => $this->isEdited(),
            'isDeleted' => $this->isDeleted(),
            'editedAt'  => $this->editedAt?->format(\DateTimeInterface::ATOM),
            'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
