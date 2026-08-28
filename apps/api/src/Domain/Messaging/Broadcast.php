<?php

declare(strict_types=1);

namespace Central\Api\Domain\Messaging;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * One announcement to an audience.
 *
 * Not a conversation: nobody replies to it, everybody gets the same words, and
 * for a policy change somebody may have to confirm they have read it. A
 * conversation with four hundred participants and no replies is a broadcast
 * wearing the wrong shape.
 *
 * The audience is a rule — "everybody in Operations" — until the moment it is
 * sent, when it becomes a list of people. That is deliberate: resolving it
 * lazily would show somebody who joined last week an announcement from before
 * they arrived, and leave somebody who has left still owing an
 * acknowledgement.
 */
final readonly class Broadcast
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $title,
        public string $content,
        public BroadcastTarget $targetType = BroadcastTarget::All,
        /** @var array<string, mixed> */
        public array $targetCriteria = [],
        public BroadcastPriority $priority = BroadcastPriority::Normal,
        public bool $requiresAcknowledgment = false,
        public ?\DateTimeImmutable $scheduledAt = null,
        public ?\DateTimeImmutable $expiresAt = null,
        public BroadcastStatus $status = BroadcastStatus::Draft,
        public ?\DateTimeImmutable $sentAt = null,
        public ?string $createdBy = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Counted on read; never stored, because all three move constantly. */
        public int $recipientCount = 0,
        public int $readCount = 0,
        public int $acknowledgedCount = 0,
        public ?string $senderName = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(
        string $id,
        string $tenantId,
        array $input,
        ?string $createdBy = null,
    ): self {
        $violations = [];

        $title = trim((string) ($input['title'] ?? ''));
        $content = trim((string) ($input['content'] ?? ''));

        if ($title === '') {
            $violations['title'] = 'Give the announcement a title.';
        } elseif (mb_strlen($title) > 255) {
            $violations['title'] = 'A title must be 255 characters or fewer.';
        }

        if ($content === '') {
            $violations['content'] = 'Say what the announcement is.';
        }

        $target = BroadcastTarget::tryFrom((string) ($input['targetType'] ?? 'all'));

        if ($target === null) {
            $violations['targetType'] = sprintf(
                'Choose one of: %s.',
                implode(', ', BroadcastTarget::values()),
            );
        }

        $priority = BroadcastPriority::tryFrom((string) ($input['priority'] ?? 'normal'));

        if ($priority === null) {
            $violations['priority'] = 'Choose normal, important or urgent.';
        }

        $criteria = is_array($input['targetCriteria'] ?? null) ? $input['targetCriteria'] : [];

        if ($target !== null && $target->needsCriteria() && $criteria === []) {
            // A broadcast aimed at "a department" with no department named
            // would resolve to nobody, and look sent.
            $violations['targetCriteria'] = sprintf(
                'Say which %s this is for.',
                $target->noun(),
            );
        }

        $scheduledAt = self::when($input['scheduledAt'] ?? null, 'scheduledAt', $violations);
        $expiresAt = self::when($input['expiresAt'] ?? null, 'expiresAt', $violations);

        if ($scheduledAt !== null && $expiresAt !== null && $expiresAt <= $scheduledAt) {
            $violations['expiresAt'] = 'It cannot expire before it is sent.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            title: $title,
            content: $content,
            targetType: $target ?? BroadcastTarget::All,
            targetCriteria: $criteria,
            priority: $priority ?? BroadcastPriority::Normal,
            requiresAcknowledgment: (bool) ($input['requiresAcknowledgment'] ?? false),
            scheduledAt: $scheduledAt,
            expiresAt: $expiresAt,
            // A broadcast with a time to go out is scheduled; one without is a
            // draft until somebody sends it.
            status: $scheduledAt === null ? BroadcastStatus::Draft : BroadcastStatus::Scheduled,
            createdBy: $createdBy,
        );
    }

    /**
     * @param array<string, string> $violations
     */
    private static function when(mixed $value, string $field, array &$violations): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            $violations[$field] = 'That is not a date and time.';

            return null;
        }
    }

    /** Whether this can still be edited. A sent announcement cannot be unsaid. */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * How far through its audience it has got.
     *
     * Zero when nobody has received it yet — rather than a division by zero,
     * and rather than 100%, which is what "all nought of them have read it"
     * would otherwise mean.
     */
    public function readPercentage(): int
    {
        if ($this->recipientCount === 0) {
            return 0;
        }

        return (int) round(($this->readCount / $this->recipientCount) * 100);
    }

    public function acknowledgedPercentage(): int
    {
        if ($this->recipientCount === 0 || !$this->requiresAcknowledgment) {
            return 0;
        }

        return (int) round(($this->acknowledgedCount / $this->recipientCount) * 100);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function toArray(array $extra = []): array
    {
        return [
            'id'             => $this->id,
            'title'          => $this->title,
            'content'        => $this->content,
            'targetType'     => $this->targetType->value,
            'targetLabel'    => $this->targetType->label(),
            'targetCriteria' => $this->targetCriteria,
            'priority'       => $this->priority->value,
            'requiresAcknowledgment' => $this->requiresAcknowledgment,
            'scheduledAt'    => $this->scheduledAt?->format(\DateTimeInterface::ATOM),
            'expiresAt'      => $this->expiresAt?->format(\DateTimeInterface::ATOM),
            'status'         => $this->status->value,
            'statusLabel'    => $this->status->label(),
            'isEditable'     => $this->isEditable(),
            'sentAt'         => $this->sentAt?->format(\DateTimeInterface::ATOM),
            // Counted at read time, never stored — all three move whenever
            // somebody opens it.
            'recipientCount'     => $this->recipientCount,
            'readCount'          => $this->readCount,
            'acknowledgedCount'  => $this->acknowledgedCount,
            'readPercentage'     => $this->readPercentage(),
            'acknowledgedPercentage' => $this->acknowledgedPercentage(),
            'createdBy'  => $this->createdBy,
            'senderName' => $this->senderName,
            'createdAt'  => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'  => $this->updatedAt?->format(\DateTimeInterface::ATOM),
            ...$extra,
        ];
    }
}
