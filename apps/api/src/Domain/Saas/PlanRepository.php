<?php

declare(strict_types=1);

namespace Central\Api\Domain\Saas;

interface PlanRepository
{
    /**
     * Every plan, retired ones included — the operator's full catalogue.
     *
     * @return list<Plan>
     */
    public function all(): array;

    /**
     * The plans offered to new customers — active and public — for the pricing
     * page. Order is the catalogue's own.
     *
     * @return list<Plan>
     */
    public function publicPlans(): array;

    public function find(string $id): ?Plan;

    public function findByCode(string $code): ?Plan;

    public function create(Plan $plan): Plan;

    /**
     * Updates the editable fields of a plan. Its code is its stable identity and
     * is not among them.
     */
    public function update(string $id, Plan $plan): ?Plan;

    public function softDelete(string $id): bool;
}
