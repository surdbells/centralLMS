<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Saas\Payment;
use Central\Api\Domain\Saas\PaymentRepository;
use Doctrine\DBAL\Connection;

final class DbalPaymentRepository implements PaymentRepository
{
    private const COLUMNS = 'id, tenant_id, invoice_id, provider, reference, gateway_reference,
        status, amount, currency, authorization_url, paid_at, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $id): ?Payment
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM payments WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByReference(string $reference): ?Payment
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM payments WHERE reference = :ref',
            ['ref' => $reference],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findPendingByInvoice(string $invoiceId): ?Payment
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . " FROM payments
             WHERE invoice_id = :i AND status = 'pending'
             ORDER BY created_at DESC LIMIT 1",
            ['i' => $invoiceId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(Payment $payment): Payment
    {
        $id = (string) $this->connection->fetchOne(
            'INSERT INTO payments
                 (tenant_id, invoice_id, provider, reference, status, amount, currency, authorization_url)
             VALUES (:tenant, :invoice, :provider, :reference, :status, :amount, :currency, :authUrl)
             RETURNING id',
            [
                'tenant'    => $payment->tenantId,
                'invoice'   => $payment->invoiceId,
                'provider'  => $payment->provider,
                'reference' => $payment->reference,
                'status'    => $payment->status,
                'amount'    => $payment->amount,
                'currency'  => $payment->currency,
                'authUrl'   => $payment->authorizationUrl,
            ],
        );

        return $this->find($id) ?? throw new \RuntimeException('The payment vanished immediately after being created.');
    }

    public function settle(
        string $id,
        string $status,
        ?string $gatewayReference,
        ?\DateTimeImmutable $paidAt,
        ?array $raw,
    ): ?Payment {
        $changed = $this->connection->executeStatement(
            'UPDATE payments SET
                 status = :status, gateway_reference = :gatewayRef, paid_at = :paidAt,
                 raw = :raw::jsonb, updated_at = now()
             WHERE id = :id',
            [
                'status'     => $status,
                'gatewayRef' => $gatewayReference,
                'paidAt'     => $paidAt?->format(\DateTimeInterface::ATOM),
                'raw'        => $raw === null ? null : json_encode($raw, JSON_THROW_ON_ERROR),
                'id'         => $id,
            ],
        );

        return $changed === 0 ? null : $this->find($id);
    }

    // --- internals ------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Payment
    {
        return new Payment(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            invoiceId: $row['invoice_id'] !== null ? (string) $row['invoice_id'] : null,
            provider: (string) $row['provider'],
            reference: (string) $row['reference'],
            gatewayReference: $row['gateway_reference'] !== null ? (string) $row['gateway_reference'] : null,
            status: (string) $row['status'],
            amount: (int) $row['amount'],
            currency: (string) $row['currency'],
            authorizationUrl: $row['authorization_url'] !== null ? (string) $row['authorization_url'] : null,
            paidAt: $this->time($row['paid_at'] ?? null),
            createdAt: $this->time($row['created_at'] ?? null),
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
