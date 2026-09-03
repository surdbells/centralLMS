<?php

declare(strict_types=1);

namespace Central\Api\Domain\Saas;

interface InvoiceRepository
{
    public function find(string $id): ?Invoice;

    /**
     * One organisation's invoices, newest first.
     *
     * @return list<Invoice>
     */
    public function forTenant(string $tenantId): array;

    /** Issues an invoice, assigning its sequential number. */
    public function create(Invoice $invoice): Invoice;

    /** Sets an invoice's state (paid / void / …), with when and how much. */
    public function updateStatus(string $id, string $status, ?\DateTimeImmutable $paidAt, int $amountPaid): ?Invoice;

    /**
     * Whether the renewal for a subscription's period has already been billed,
     * so the job never issues the same period twice.
     */
    public function existsForPeriod(string $subscriptionId, \DateTimeImmutable $periodEnd): bool;

    /**
     * Recent invoices across every organisation, with the organisation named —
     * a read model for the operator's platform billing view.
     *
     * @return list<array<string, mixed>>
     */
    public function recentWithTenant(int $limit = 50): array;

    /**
     * Platform invoice totals, in minor units.
     *
     * @return array{count:int, open:int, outstanding:int, collected:int}
     */
    public function platformTotals(): array;
}
