<?php

declare(strict_types=1);

namespace Central\Api\Domain\Saas;

/**
 * One pass through a gateway's checkout to collect an invoice.
 *
 * Begun 'pending' with our reference; the gateway confirms it 'success' (or it
 * fails / is abandoned). The amount is in minor units, matching the invoice it
 * settles.
 */
final readonly class Payment
{
    public const STATUSES = ['pending', 'success', 'failed', 'abandoned'];

    public function __construct(
        public ?string $id,
        public string $tenantId,
        public ?string $invoiceId,
        public string $provider,
        public string $reference,
        public ?string $gatewayReference,
        public string $status,
        public int $amount,
        public string $currency,
        public ?string $authorizationUrl,
        public ?\DateTimeImmutable $paidAt = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'tenantId'         => $this->tenantId,
            'invoiceId'        => $this->invoiceId,
            'provider'         => $this->provider,
            'reference'        => $this->reference,
            'gatewayReference' => $this->gatewayReference,
            'status'           => $this->status,
            'amount'           => $this->amount,
            'currency'         => $this->currency,
            'authorizationUrl' => $this->authorizationUrl,
            'paidAt'           => $this->paidAt?->format(\DateTimeInterface::ATOM),
            'createdAt'        => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
