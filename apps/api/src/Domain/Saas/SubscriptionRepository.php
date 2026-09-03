<?php

declare(strict_types=1);

namespace Central\Api\Domain\Saas;

interface SubscriptionRepository
{
    public function find(string $id): ?Subscription;

    /** The organisation's current subscription, or null if it has none. */
    public function findByTenant(string $tenantId): ?Subscription;

    /**
     * Inserts the tenant's subscription, or updates it in place — there is one
     * per organisation.
     */
    public function save(Subscription $subscription): Subscription;

    /**
     * Live subscriptions whose current period has ended and are not set to
     * cancel — the ones the renewal job acts on. Oldest period end first.
     *
     * @return list<Subscription>
     */
    public function due(\DateTimeImmutable $now, int $limit = 200): array;

    /** Monthly recurring revenue across active subscriptions, in minor units. */
    public function monthlyRecurringRevenue(): int;

    /**
     * How many subscriptions are in each status.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array;
}
