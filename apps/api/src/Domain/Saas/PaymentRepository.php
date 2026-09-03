<?php

declare(strict_types=1);

namespace Central\Api\Domain\Saas;

interface PaymentRepository
{
    public function find(string $id): ?Payment;

    /** By our own reference — how a callback and a webhook find their payment. */
    public function findByReference(string $reference): ?Payment;

    /**
     * The checkout already in progress for an invoice, if any — so a second
     * "pay" reuses it rather than starting another the customer could also pay.
     */
    public function findPendingByInvoice(string $invoiceId): ?Payment;

    public function create(Payment $payment): Payment;

    /**
     * Records the outcome of a payment: its status, the gateway's reference,
     * when it was paid, and the raw confirmation for the trail.
     *
     * @param array<string, mixed>|null $raw
     */
    public function settle(
        string $id,
        string $status,
        ?string $gatewayReference,
        ?\DateTimeImmutable $paidAt,
        ?array $raw,
    ): ?Payment;
}
