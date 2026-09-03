<?php

declare(strict_types=1);

namespace Central\Api\Domain\Saas;

/**
 * A bill for one billing period.
 *
 * Issued against a subscription, numbered in sequence, and settled by being
 * marked paid — by the operator now, by a payment when the gateway lands. Voids
 * and write-offs are terminal states that stop it counting as owed.
 */
final readonly class Invoice
{
    public const STATUSES = ['draft', 'open', 'paid', 'void', 'uncollectible'];

    public function __construct(
        public ?string $id,
        public string $tenantId,
        public ?string $subscriptionId,
        public string $number,
        public string $status,
        public ?string $description,
        public int $amountDue,
        public int $amountPaid,
        public string $currency,
        public ?\DateTimeImmutable $periodStart,
        public ?\DateTimeImmutable $periodEnd,
        public ?\DateTimeImmutable $issuedAt,
        public ?\DateTimeImmutable $dueAt,
        public ?\DateTimeImmutable $paidAt,
    ) {
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** Whether it still counts as money owed. */
    public function isOutstanding(): bool
    {
        return in_array($this->status, ['open', 'draft'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'tenantId'       => $this->tenantId,
            'subscriptionId' => $this->subscriptionId,
            'number'         => $this->number,
            'status'         => $this->status,
            'description'    => $this->description,
            'amountDue'      => $this->amountDue,
            'amountPaid'     => $this->amountPaid,
            'currency'       => $this->currency,
            'periodStart'    => $this->periodStart?->format(\DateTimeInterface::ATOM),
            'periodEnd'      => $this->periodEnd?->format(\DateTimeInterface::ATOM),
            'issuedAt'       => $this->issuedAt?->format(\DateTimeInterface::ATOM),
            'dueAt'          => $this->dueAt?->format(\DateTimeInterface::ATOM),
            'paidAt'         => $this->paidAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
