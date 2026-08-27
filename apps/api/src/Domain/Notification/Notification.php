<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * One thing one person is being told.
 *
 * The wording is stored, not looked up. See NotificationComposer.
 */
final readonly class Notification
{
    /**
     * @param array<string, mixed>       $data
     * @param list<NotificationDelivery> $deliveries
     */
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $userId,
        public NotificationType $type,
        public string $title,
        public string $body = '',
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public array $data = [],
        public NotificationPriority $priority = NotificationPriority::Normal,
        public ?\DateTimeImmutable $readAt = null,
        public ?\DateTimeImmutable $expiresAt = null,
        public ?\DateTimeImmutable $createdAt = null,
        public array $deliveries = [],
    ) {
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    /**
     * Whether it should still be shown.
     *
     * Expiry is checked on read rather than swept by a job. A row that has
     * expired but not yet been tidied away must not reappear on the bell
     * because the sweeper is behind.
     */
    public function hasExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    /**
     * Builds a notification for a recipient, with the wording composed and
     * the expiry derived from the type.
     *
     * @param array<string, mixed>                $data
     * @param array{title: string, body: string}|null $words wording already
     *        decided by the caller — the organisation's own, where it has
     *        written any. Absent, the platform default is composed here.
     */
    public static function raise(
        string $tenantId,
        string $userId,
        NotificationType $type,
        array $data,
        NotificationComposer $composer,
        \DateTimeImmutable $now,
        ?string $subjectId = null,
        ?NotificationPriority $priority = null,
        ?array $words = null,
    ): self {
        $words ??= $composer->compose($type, $data);

        $lifetime  = $type->livesForDays();
        $expiresAt = $lifetime === null ? null : $now->modify(sprintf('+%d days', $lifetime));

        // A subject type without an id is rejected by the schema, so the pair
        // is resolved together rather than trusting the caller to match them.
        $subjectType = $subjectId === null ? null : $type->subjectType();
        $subjectId   = $subjectType === null ? null : $subjectId;

        return new self(
            id: null,
            tenantId: $tenantId,
            userId: $userId,
            type: $type,
            title: $words['title'],
            body: $words['body'],
            subjectType: $subjectType,
            subjectId: $subjectId,
            data: $data,
            priority: $priority ?? $type->priority(),
            expiresAt: $expiresAt,
            createdAt: $now,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'type'        => $this->type->value,
            'typeLabel'   => $this->type->label(),
            'title'       => $this->title,
            'body'        => $this->body,
            'subjectType' => $this->subjectType,
            'subjectId'   => $this->subjectId,
            'data'        => (object) $this->data,
            'priority'    => $this->priority->value,
            'isRead'      => $this->isRead(),
            'readAt'      => $this->readAt?->format(\DateTimeInterface::ATOM),
            'expiresAt'   => $this->expiresAt?->format(\DateTimeInterface::ATOM),
            'createdAt'   => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * The admin view, which adds where it was sent and what happened.
     *
     * @return array<string, mixed>
     */
    public function toArrayWithDeliveries(): array
    {
        return [
            ...$this->toArray(),
            'userId'     => $this->userId,
            'deliveries' => array_map(
                static fn (NotificationDelivery $d): array => $d->toArray(),
                $this->deliveries,
            ),
        ];
    }
}
