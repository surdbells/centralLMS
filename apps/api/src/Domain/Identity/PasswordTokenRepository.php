<?php

declare(strict_types=1);

namespace Central\Api\Domain\Identity;

interface PasswordTokenRepository
{
    /**
     * Stores a token's hash and returns the record.
     *
     * The secret is not passed back out. Whoever generated it is the only
     * thing that ever holds it, and it goes straight into an email.
     */
    public function issue(
        string $tenantId,
        string $userId,
        PasswordTokenPurpose $purpose,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        ?string $ip = null,
        ?string $userAgent = null,
    ): PasswordToken;

    /**
     * Finds a token by the hash of the secret presented.
     *
     * Not scoped to a tenant, because nobody redeeming one is signed in and so
     * nothing has said which tenant they belong to. The token is the only
     * thing identifying anybody, which is why it carries 256 bits.
     */
    public function findByHash(string $tokenHash): ?PasswordToken;

    /**
     * Marks it spent, and returns whether this call was the one that did it.
     *
     * False means somebody — or something replaying the same link — got there
     * first. The caller must treat that as a refusal rather than proceeding,
     * which is why it is a claim and not a `markUsed()`.
     */
    public function claim(string $id, \DateTimeImmutable $at): bool;

    /**
     * Retires a person's outstanding tokens of a purpose.
     *
     * Run when a new one is issued and again when a password is set by any
     * route, so an old reset link in an old email stops working the moment a
     * newer one is asked for.
     *
     * @return int how many were retired
     */
    public function retireFor(string $userId, ?PasswordTokenPurpose $purpose, \DateTimeImmutable $at): int;

    /**
     * How many resets this address has asked for since a moment.
     *
     * The reset endpoint answers the same way whether or not an account
     * exists, so the only thing standing between it and being used to send
     * somebody a hundred emails is this.
     */
    public function countRecent(string $userId, PasswordTokenPurpose $purpose, \DateTimeImmutable $since): int;
}
