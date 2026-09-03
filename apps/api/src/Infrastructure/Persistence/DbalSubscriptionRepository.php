<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Saas\Subscription;
use Central\Api\Domain\Saas\SubscriptionRepository;
use Doctrine\DBAL\Connection;

final class DbalSubscriptionRepository implements SubscriptionRepository
{
    private const COLUMNS = 'id, tenant_id, plan_id, billing_cycle, status, amount, currency,
        current_period_start, current_period_end, trial_ends_at, cancel_at_period_end, canceled_at,
        started_at, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $id): ?Subscription
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM subscriptions WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByTenant(string $tenantId): ?Subscription
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM subscriptions WHERE tenant_id = :t',
            ['t' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function save(Subscription $subscription): Subscription
    {
        // One subscription per tenant: insert, or update the existing one in
        // place. started_at and created_at are never overwritten — they record
        // when this arrangement first began.
        $id = (string) $this->connection->fetchOne(
            'INSERT INTO subscriptions
                 (tenant_id, plan_id, billing_cycle, status, amount, currency,
                  current_period_start, current_period_end, trial_ends_at,
                  cancel_at_period_end, canceled_at)
             VALUES (:tenant, :plan, :cycle, :status, :amount, :currency,
                     :periodStart, :periodEnd, :trialEnds, :cancelAtEnd, :canceledAt)
             ON CONFLICT (tenant_id) DO UPDATE SET
                 plan_id = EXCLUDED.plan_id, billing_cycle = EXCLUDED.billing_cycle,
                 status = EXCLUDED.status, amount = EXCLUDED.amount, currency = EXCLUDED.currency,
                 current_period_start = EXCLUDED.current_period_start,
                 current_period_end = EXCLUDED.current_period_end,
                 trial_ends_at = EXCLUDED.trial_ends_at,
                 cancel_at_period_end = EXCLUDED.cancel_at_period_end,
                 canceled_at = EXCLUDED.canceled_at,
                 updated_at = now()
             RETURNING id',
            $this->params($subscription),
        );

        return $this->find($id) ?? throw new \RuntimeException('The subscription vanished immediately after being saved.');
    }

    public function due(\DateTimeImmutable $now, int $limit = 200): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM subscriptions
             WHERE status IN (\'trialing\', \'active\')
               AND current_period_end IS NOT NULL
               AND current_period_end <= :now
             ORDER BY current_period_end ASC
             LIMIT :limit',
            ['now' => $now->format(\DateTimeInterface::ATOM), 'limit' => $limit],
        );

        return array_map(fn (array $r): Subscription => $this->hydrate($r), $rows);
    }

    public function monthlyRecurringRevenue(): int
    {
        // Normalise a yearly price to a month so the headline is comparable.
        return (int) $this->connection->fetchOne(
            "SELECT COALESCE(SUM(
                 CASE WHEN billing_cycle = 'yearly' THEN round(amount / 12.0) ELSE amount END
             ), 0)
             FROM subscriptions
             WHERE status = 'active' AND amount > 0",
        );
    }

    public function countByStatus(): array
    {
        /** @var array<string, int> $counts */
        $counts = $this->connection->fetchAllKeyValue(
            'SELECT status, COUNT(*) FROM subscriptions GROUP BY status',
        );

        return array_map(static fn ($v): int => (int) $v, $counts);
    }

    // --- internals ------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Subscription
    {
        return new Subscription(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            planId: $row['plan_id'] !== null ? (string) $row['plan_id'] : null,
            billingCycle: (string) $row['billing_cycle'],
            status: (string) $row['status'],
            amount: (int) $row['amount'],
            currency: (string) $row['currency'],
            currentPeriodStart: $this->time($row['current_period_start'] ?? null),
            currentPeriodEnd: $this->time($row['current_period_end'] ?? null),
            trialEndsAt: $this->time($row['trial_ends_at'] ?? null),
            cancelAtPeriodEnd: (bool) $row['cancel_at_period_end'],
            canceledAt: $this->time($row['canceled_at'] ?? null),
            startedAt: $this->time($row['started_at'] ?? null),
            createdAt: $this->time($row['created_at'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function params(Subscription $s): array
    {
        return [
            'tenant'      => $s->tenantId,
            'plan'        => $s->planId,
            'cycle'       => $s->billingCycle,
            'status'      => $s->status,
            'amount'      => $s->amount,
            'currency'    => $s->currency,
            'periodStart' => $s->currentPeriodStart?->format(\DateTimeInterface::ATOM),
            'periodEnd'   => $s->currentPeriodEnd?->format(\DateTimeInterface::ATOM),
            'trialEnds'   => $s->trialEndsAt?->format(\DateTimeInterface::ATOM),
            'cancelAtEnd' => $s->cancelAtPeriodEnd ? 'true' : 'false',
            'canceledAt'  => $s->canceledAt?->format(\DateTimeInterface::ATOM),
        ];
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
