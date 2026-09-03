<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Saas\Plan;
use Central\Api\Domain\Saas\PlanRepository;
use Doctrine\DBAL\Connection;

final class DbalPlanRepository implements PlanRepository
{
    private const COLUMNS = 'id, code, name, description, price_monthly, price_yearly, currency,
        users_limit, storage_limit_mb, features, trial_days, is_active, is_public, sort_order, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function all(): array
    {
        return $this->hydrateAll($this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM plans WHERE deleted_at IS NULL ORDER BY sort_order ASC, name ASC',
        ));
    }

    public function publicPlans(): array
    {
        return $this->hydrateAll($this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM plans
             WHERE deleted_at IS NULL AND is_active AND is_public
             ORDER BY sort_order ASC, price_monthly ASC',
        ));
    }

    public function find(string $id): ?Plan
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM plans WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByCode(string $code): ?Plan
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM plans WHERE lower(code) = lower(:code) AND deleted_at IS NULL',
            ['code' => $code],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(Plan $plan): Plan
    {
        $id = (string) $this->connection->fetchOne(
            'INSERT INTO plans
                 (code, name, description, price_monthly, price_yearly, currency,
                  users_limit, storage_limit_mb, features, trial_days, is_active, is_public, sort_order)
             VALUES (:code, :name, :description, :priceMonthly, :priceYearly, :currency,
                     :usersLimit, :storageLimitMb, :features::jsonb, :trialDays, :isActive, :isPublic, :sortOrder)
             RETURNING id',
            $this->params($plan),
        );

        return $this->find($id) ?? throw new \RuntimeException('The plan vanished immediately after being created.');
    }

    public function update(string $id, Plan $plan): ?Plan
    {
        $changed = $this->connection->executeStatement(
            'UPDATE plans SET
                 name = :name, description = :description,
                 price_monthly = :priceMonthly, price_yearly = :priceYearly, currency = :currency,
                 users_limit = :usersLimit, storage_limit_mb = :storageLimitMb,
                 features = :features::jsonb, trial_days = :trialDays,
                 is_active = :isActive, is_public = :isPublic, sort_order = :sortOrder,
                 updated_at = now()
             WHERE id = :id AND deleted_at IS NULL',
            [...$this->params($plan), 'id' => $id],
        );

        return $changed === 0 ? null : $this->find($id);
    }

    public function softDelete(string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE plans SET deleted_at = now() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        ) > 0;
    }

    // --- internals ------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>> $rows
     * @return list<Plan>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(fn (array $row): Plan => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Plan
    {
        $features = $row['features'] ?? null;
        if (is_string($features)) {
            $features = json_decode($features, true);
        }

        return new Plan(
            id: (string) $row['id'],
            code: (string) $row['code'],
            name: (string) $row['name'],
            description: $row['description'] !== null ? (string) $row['description'] : null,
            priceMonthly: (int) $row['price_monthly'],
            priceYearly: (int) $row['price_yearly'],
            currency: (string) $row['currency'],
            usersLimit: $row['users_limit'] !== null ? (int) $row['users_limit'] : null,
            storageLimitMb: $row['storage_limit_mb'] !== null ? (int) $row['storage_limit_mb'] : null,
            features: is_array($features) ? $features : [],
            trialDays: (int) $row['trial_days'],
            isActive: (bool) $row['is_active'],
            isPublic: (bool) $row['is_public'],
            sortOrder: (int) $row['sort_order'],
            createdAt: $this->time($row['created_at'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function params(Plan $plan): array
    {
        return [
            'code'           => $plan->code,
            'name'           => $plan->name,
            'description'    => $plan->description,
            'priceMonthly'   => $plan->priceMonthly,
            'priceYearly'    => $plan->priceYearly,
            'currency'       => $plan->currency,
            'usersLimit'     => $plan->usersLimit,
            'storageLimitMb' => $plan->storageLimitMb,
            'features'       => json_encode((object) $plan->features, JSON_THROW_ON_ERROR),
            'trialDays'      => $plan->trialDays,
            'isActive'       => $plan->isActive ? 'true' : 'false',
            'isPublic'       => $plan->isPublic ? 'true' : 'false',
            'sortOrder'      => $plan->sortOrder,
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
