<?php

declare(strict_types=1);

namespace Central\Api\Domain\Identity;

interface UserRepository
{
    /**
     * Sign-in lookup.
     *
     * Email is unique per tenant, not globally — the same person can hold
     * accounts in two organisations. `$tenantId` is null when the caller has
     * not yet been resolved to a tenant (a login form with no subdomain), in
     * which case an email matching in exactly one tenant is returned and an
     * ambiguous one is not.
     */
    public function findForSignIn(string $email, ?string $tenantId = null): ?User;

    public function find(string $id): ?User;

    public function findByTenant(string $tenantId, string $id): ?User;

    /**
     * Records a successful sign-in. Separate from `save()` because it runs on
     * the hot path and must not rewrite the whole row.
     */
    public function recordSuccessfulLogin(string $userId, \DateTimeImmutable $at): void;

    /**
     * Replaces a password hash — used both for a reset and for the
     * rehash-on-login upgrade when the work factor changes.
     */
    public function updatePasswordHash(string $userId, string $hash): void;
}
