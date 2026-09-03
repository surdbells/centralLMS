<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Saas\Invoice;
use Central\Api\Domain\Saas\InvoiceRepository;
use Doctrine\DBAL\Connection;

final class DbalInvoiceRepository implements InvoiceRepository
{
    private const COLUMNS = 'id, tenant_id, subscription_id, number, status, description,
        amount_due, amount_paid, currency, period_start, period_end, issued_at, due_at, paid_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $id): ?Invoice
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM invoices WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function forTenant(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM invoices WHERE tenant_id = :t ORDER BY issued_at DESC, number DESC',
            ['t' => $tenantId],
        );

        return array_map(fn (array $r): Invoice => $this->hydrate($r), $rows);
    }

    public function create(Invoice $invoice): Invoice
    {
        // The number is the database's to assign, from the shared sequence, so
        // two invoices raised at once cannot collide or leave a gap.
        $id = (string) $this->connection->fetchOne(
            "INSERT INTO invoices
                 (tenant_id, subscription_id, number, status, description,
                  amount_due, amount_paid, currency, period_start, period_end, due_at)
             VALUES (:tenant, :subscription,
                     'INV-' || lpad(nextval('invoice_number_seq')::text, 6, '0'),
                     :status, :description, :amountDue, :amountPaid, :currency,
                     :periodStart, :periodEnd, :dueAt)
             RETURNING id",
            [
                'tenant'       => $invoice->tenantId,
                'subscription' => $invoice->subscriptionId,
                'status'       => $invoice->status,
                'description'  => $invoice->description,
                'amountDue'    => $invoice->amountDue,
                'amountPaid'   => $invoice->amountPaid,
                'currency'     => $invoice->currency,
                'periodStart'  => $invoice->periodStart?->format(\DateTimeInterface::ATOM),
                'periodEnd'    => $invoice->periodEnd?->format(\DateTimeInterface::ATOM),
                'dueAt'        => $invoice->dueAt?->format(\DateTimeInterface::ATOM),
            ],
        );

        return $this->find($id) ?? throw new \RuntimeException('The invoice vanished immediately after being created.');
    }

    public function updateStatus(string $id, string $status, ?\DateTimeImmutable $paidAt, int $amountPaid): ?Invoice
    {
        $changed = $this->connection->executeStatement(
            'UPDATE invoices SET status = :status, paid_at = :paidAt, amount_paid = :amountPaid, updated_at = now()
             WHERE id = :id',
            [
                'status'     => $status,
                'paidAt'     => $paidAt?->format(\DateTimeInterface::ATOM),
                'amountPaid' => $amountPaid,
                'id'         => $id,
            ],
        );

        return $changed === 0 ? null : $this->find($id);
    }

    public function existsForPeriod(string $subscriptionId, \DateTimeImmutable $periodEnd): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM invoices WHERE subscription_id = :s AND period_end = :end',
            ['s' => $subscriptionId, 'end' => $periodEnd->format(\DateTimeInterface::ATOM)],
        ) !== false;
    }

    public function recentWithTenant(int $limit = 50): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::prefixed('i') . ",
                    t.name AS tenant_name, t.code AS tenant_code
             FROM invoices i
             JOIN tenants t ON t.id = i.tenant_id
             ORDER BY i.issued_at DESC, i.number DESC
             LIMIT :limit",
            ['limit' => $limit],
        );

        return array_map(function (array $r): array {
            return $this->hydrate($r)->toArray() + [
                'tenantName' => (string) $r['tenant_name'],
                'tenantCode' => (string) $r['tenant_code'],
            ];
        }, $rows);
    }

    public function platformTotals(): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                 COUNT(*) AS count,
                 COUNT(*) FILTER (WHERE status = 'open') AS open,
                 COALESCE(SUM(amount_due) FILTER (WHERE status IN ('open', 'draft')), 0) AS outstanding,
                 COALESCE(SUM(amount_paid) FILTER (WHERE status = 'paid'), 0) AS collected
             FROM invoices",
        ) ?: [];

        return [
            'count'       => (int) ($row['count'] ?? 0),
            'open'        => (int) ($row['open'] ?? 0),
            'outstanding' => (int) ($row['outstanding'] ?? 0),
            'collected'   => (int) ($row['collected'] ?? 0),
        ];
    }

    // --- internals ------------------------------------------------------------

    private static function prefixed(string $alias): string
    {
        return implode(', ', array_map(
            static fn (string $c): string => $alias . '.' . trim($c),
            explode(',', self::COLUMNS),
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Invoice
    {
        return new Invoice(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            subscriptionId: $row['subscription_id'] !== null ? (string) $row['subscription_id'] : null,
            number: (string) $row['number'],
            status: (string) $row['status'],
            description: $row['description'] !== null ? (string) $row['description'] : null,
            amountDue: (int) $row['amount_due'],
            amountPaid: (int) $row['amount_paid'],
            currency: (string) $row['currency'],
            periodStart: $this->time($row['period_start'] ?? null),
            periodEnd: $this->time($row['period_end'] ?? null),
            issuedAt: $this->time($row['issued_at'] ?? null),
            dueAt: $this->time($row['due_at'] ?? null),
            paidAt: $this->time($row['paid_at'] ?? null),
        );
    }

    private function time(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
