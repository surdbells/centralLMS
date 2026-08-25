<?php

declare(strict_types=1);

namespace Central\Api\Application\Auth;

use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Exception\AuthenticationFailed;
use Central\Api\Domain\Identity\RefreshTokenRepository;
use Central\Api\Domain\Identity\User;
use Central\Api\Domain\Identity\UserRepository;
use Central\Api\Infrastructure\Security\JwtTokenService;
use Central\Api\Infrastructure\Security\PasswordHasher;
use Central\Api\Infrastructure\Security\TokenPair;
use Central\Api\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * Sign in, refresh, sign out.
 *
 * Replaces the legacy `Auth::user_login()` and `Database::verify_user()` — the
 * latter of which hardcoded `return 100`, so no token was ever actually
 * checked and the whole API was open to anyone sending arbitrary values.
 */
final class AuthenticationService
{
    /**
     * A hash of a value nobody knows, verified against when the email is
     * unknown so that a failed sign-in costs the same either way.
     *
     * Generated from the configured hasher rather than hardcoded, because the
     * cost factor has to match what real accounts are stored at. A fixed
     * cost-12 literal against cost-4 accounts made the *unknown*-email path
     * 230x slower than the known one — an enumeration oracle just as usable as
     * the one it was meant to close.
     */
    private ?string $timingDecoy = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly PasswordHasher $passwords,
        private readonly JwtTokenService $tokens,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws AuthenticationFailed on any failure — never distinguishing an
     *                              unknown email from a wrong password
     */
    public function login(
        string $email,
        string $password,
        ?string $tenantId = null,
        ?string $userAgent = null,
        ?string $ipAddress = null,
    ): AuthenticationResult {
        $user = $this->users->findForSignIn($email, $tenantId);

        // Always verify against something, so an unknown address costs the
        // same time as a known one.
        $hash = $user !== null && $user->passwordHash !== null
            ? $user->passwordHash
            : $this->timingDecoy();
        $passwordMatches = $this->passwords->verify($password, $hash);

        if ($user === null || !$passwordMatches) {
            $this->logger->info('Sign-in rejected', [
                'email'  => $email,
                'reason' => $user === null ? 'unknown_email' : 'bad_password',
                'ip'     => $ipAddress,
            ]);

            throw new AuthenticationFailed(
                'That email and password combination is not recognised.',
                'bad_credentials',
            );
        }

        if (!$user->canAuthenticateWithPassword()) {
            $this->logger->info('Sign-in refused for account state', [
                'userId' => $user->id,
                'status' => $user->status->value,
            ]);

            throw new AuthenticationFailed($user->status->signInRefusalReason(), 'account_' . $user->status->value);
        }

        // The password was correct, so this is the one moment the plaintext is
        // available to upgrade a hash produced at a lower cost factor.
        if ($this->passwords->needsRehash($hash)) {
            $this->users->updatePasswordHash($user->id, $this->passwords->hash($password));
        }

        $now = $this->clock->now();
        $this->users->recordSuccessfulLogin($user->id, $now);

        return $this->issueFor($user, $userAgent, $ipAddress);
    }

    /**
     * Exchanges a refresh token for a new pair.
     *
     * The old token is revoked and linked to its replacement, so a stolen
     * token used after the legitimate client has refreshed is already dead.
     *
     * @throws AuthenticationFailed
     */
    public function refresh(
        string $refreshToken,
        ?string $userAgent = null,
        ?string $ipAddress = null,
    ): AuthenticationResult {
        $claims = $this->tokens->verifyRefreshToken($refreshToken);
        $now = $this->clock->now();

        // Signature alone is not enough: the token must also still be live
        // server-side, or "sign out" would mean nothing for thirty days.
        if (!$this->refreshTokens->isActive($refreshToken, $now)) {
            $this->logger->notice('Refresh token rejected: revoked, expired or unknown', [
                'userId' => $claims['sub'] ?? null,
                'ip'     => $ipAddress,
            ]);

            throw new AuthenticationFailed('Your session has ended. Please sign in again.', 'refresh_revoked');
        }

        $user = $this->users->find((string) ($claims['sub'] ?? ''));

        if ($user === null || !$user->status->canSignIn()) {
            // Suspended mid-session: the refresh must not renew access.
            $this->refreshTokens->revoke($refreshToken, $now);

            throw new AuthenticationFailed('Your session has ended. Please sign in again.', 'user_unavailable');
        }

        $pair = $this->tokens->issue($user->id, $user->email, $user->roleIds, $user->tenantId);

        $this->refreshTokens->rotate(
            $refreshToken,
            $pair->refreshToken,
            $now->modify(sprintf('+%d seconds', $this->tokens->refreshTokenTtl())),
            $now,
        );

        return new AuthenticationResult($pair, $user, $this->describe($user));
    }

    /**
     * Ends the session the refresh token belongs to.
     *
     * Deliberately silent about an unknown token: sign-out must succeed from
     * the caller's point of view whatever state the token was in.
     */
    public function logout(string $refreshToken): void
    {
        if (trim($refreshToken) === '') {
            return;
        }

        $this->refreshTokens->revoke($refreshToken, $this->clock->now());
    }

    /**
     * Ends every session for a user — used after a password change or an
     * administrator suspending the account.
     */
    public function logoutEverywhere(string $userId): int
    {
        $revoked = $this->refreshTokens->revokeAllForUser($userId, $this->clock->now());

        $this->logger->info('All sessions revoked', ['userId' => $userId, 'count' => $revoked]);

        return $revoked;
    }

    /**
     * Built once per process, on the first sign-in that needs it, so the cost
     * of generating it is not paid by deployments that never see a bad email.
     */
    private function timingDecoy(): string
    {
        return $this->timingDecoy ??= $this->passwords->hash(bin2hex(random_bytes(16)));
    }

    private function issueFor(User $user, ?string $userAgent, ?string $ipAddress): AuthenticationResult
    {
        $pair = $this->tokens->issue($user->id, $user->email, $user->roleIds, $user->tenantId);

        $this->refreshTokens->store(
            $user->id,
            $pair->refreshToken,
            $this->clock->now()->modify(sprintf('+%d seconds', $this->tokens->refreshTokenTtl())),
            $userAgent,
            $ipAddress,
        );

        return new AuthenticationResult($pair, $user, $this->describe($user));
    }

    /**
     * Resolves the caller's roles so the response can carry the permission
     * list the clients drive their navigation from.
     */
    private function describe(User $user): AuthenticatedUser
    {
        return new AuthenticatedUser(
            id: $user->id,
            tenantId: $user->tenantId,
            email: $user->email,
            roles: $this->roles->findForTenant($user->tenantId, $user->roleIds),
        );
    }
}
