<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * One organisation's own wording for one kind of notification.
 *
 * {@see NotificationComposer} holds the default: one set of English sentences
 * for every tenant on the platform. That is a reasonable default and a poor
 * only option — a company with its own tone, its own signature, or its own
 * language has nothing to change.
 *
 * A row overrides the default for one tenant and one type. No row means the
 * default stands, so an organisation that has never opened the screen is
 * unaffected, and there is no migration that copies the defaults into every
 * tenant and then has to keep thousands of copies in step.
 *
 * `subject` and `body` are separately optional: an organisation that wants its
 * own subject line and the standard body sets one and leaves the other null.
 */
final readonly class EmailTemplate
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public NotificationType $type,
        public ?string $subject = null,
        public ?string $body = null,
        /**
         * Off sends the default while keeping this wording.
         *
         * So a half-written template can be put aside without losing it, and
         * without silently going out to people in the meantime.
         */
        public bool $enabled = true,
        public ?string $updatedBy = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    /** Whether this actually overrides anything. */
    public function overrides(): bool
    {
        return $this->enabled && ($this->subject !== null || $this->body !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'type'      => $this->type->value,
            'typeLabel' => $this->type->label(),
            'subject'   => $this->subject,
            'body'      => $this->body,
            'enabled'   => $this->enabled,
            'overrides' => $this->overrides(),
            'updatedBy' => $this->updatedBy,
            'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
