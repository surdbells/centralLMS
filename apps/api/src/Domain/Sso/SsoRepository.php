<?php

declare(strict_types=1);

namespace Central\Api\Domain\Sso;

interface SsoRepository
{
    /**
     * @return list<SsoConnection>
     */
    public function forTenant(string $tenantId): array;

    public function find(string $tenantId, SsoProvider $provider): ?SsoConnection;

    /** Creates or replaces the tenant's arrangement with one provider. */
    public function save(SsoConnection $connection): SsoConnection;

    public function delete(string $tenantId, SsoProvider $provider): bool;

    // --- the redirect in flight ------------------------------------------

    /**
     * Records a redirect before it is sent.
     *
     * Without this a callback is indistinguishable from a forgery: it arrives
     * carrying a code and a state, and only a state we know we issued makes
     * the difference.
     */
    public function rememberState(
        string $state,
        string $tenantId,
        SsoProvider $provider,
        string $nonce,
        ?string $redirectTo,
        \DateTimeImmutable $expiresAt,
    ): void;

    /**
     * Takes a state back, once.
     *
     * Returns null when it was never issued, has expired, or has already been
     * used — which are one answer on purpose. Deleting and returning in one
     * statement is what makes a replayed callback fail.
     *
     * @return array{tenantId: string, provider: SsoProvider, nonce: string, redirectTo: string|null}|null
     */
    public function claimState(string $state, \DateTimeImmutable $now): ?array;

    /**
     * Clears redirects nobody came back from.
     *
     * @return int how many were cleared
     */
    public function purgeStates(\DateTimeImmutable $before): int;

    // --- the account ------------------------------------------------------

    /**
     * Finds somebody by the provider's own id for them.
     *
     * Preferred over the email address because it survives a rename: matching
     * on email alone means somebody who marries becomes a second account.
     */
    public function findUserBySubject(SsoProvider $provider, string $subject): ?string;

    /** Records which directory an account signs in from. */
    public function linkUser(string $userId, SsoProvider $provider, string $subject): void;
}
