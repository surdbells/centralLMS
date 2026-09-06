<?php

declare(strict_types=1);

namespace Central\Api\Domain\Tenant;

interface TenantRepository
{
    public function find(string $tenantId): ?Tenant;

    /**
     * Resolves an organisation from its short code (case-insensitive).
     *
     * The public counterpart to {@see find()}: a device has no subdomain, so a
     * learner names their institution by the code it was given, and the sign-in
     * screen turns it into the tenant whose branding to wear and whose users to
     * authenticate against.
     */
    public function findByCode(string $code): ?Tenant;

    /**
     * The name alone.
     *
     * Wanted in several places that need nothing else — a certificate, an
     * invitation email, a registration link's front door — and reading a whole
     * record for one string is how a join creeps into an email.
     */
    public function nameOf(string $tenantId): ?string;

    /**
     * Saves what an organisation may change about itself.
     *
     * Not the code, the status or the limits: the first is in every link
     * already handed out, and the other two are what the organisation is
     * paying for rather than what it has chosen.
     *
     * @param array<string, string|null> $changes
     */
    public function updateProfile(string $tenantId, array $changes): ?Tenant;

    /**
     * How much of what it is entitled to an organisation is using.
     *
     * Counted, never stored. The legacy kept `user_count` on the tenant row and
     * it had drifted on every tenant it was checked against.
     *
     * @return array{users: int, storageMb: float, courses: int}
     */
    public function usage(string $tenantId): array;
}
