<?php

declare(strict_types=1);

namespace Central\Api\Domain\Identity;

/**
 * Server-side record of issued refresh tokens.
 *
 * The access token is stateless — that is the point of a JWT — but the refresh
 * token must be revocable, or "sign out" and "revoke this session" mean
 * nothing for up to thirty days.
 *
 * Only a SHA-256 hash of each token is stored, so a database leak yields
 * nothing replayable.
 */
interface RefreshTokenRepository
{
    public function store(
        string $userId,
        string $token,
        \DateTimeImmutable $expiresAt,
        ?string $userAgent = null,
        ?string $ipAddress = null,
    ): string;

    /**
     * Whether this exact token is still usable: known, unrevoked, unexpired.
     */
    public function isActive(string $token, \DateTimeImmutable $now): bool;

    /**
     * Rotates a token: revokes the old one and links it to its replacement.
     *
     * Rotation on every refresh is what makes theft detectable — a stolen
     * token used after the legitimate client has refreshed will already be
     * revoked.
     */
    public function rotate(
        string $oldToken,
        string $newToken,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): void;

    public function revoke(string $token, \DateTimeImmutable $now): void;

    /**
     * Ends every session for a user — password change, admin suspension, or a
     * suspected compromise.
     *
     * @return int number of tokens revoked
     */
    public function revokeAllForUser(string $userId, \DateTimeImmutable $now): int;

    /**
     * Housekeeping for the cron: drops rows that expired long ago.
     *
     * @return int number of rows deleted
     */
    public function deleteExpiredBefore(\DateTimeImmutable $cutoff): int;
}
