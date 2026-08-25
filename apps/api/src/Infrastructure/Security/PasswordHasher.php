<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Security;

/**
 * Password hashing and verification.
 *
 * The legacy system uses bcrypt, so migrated hashes verify directly — no
 * password reset for existing users, which is the single most important
 * property of this migration.
 *
 * bcrypt is kept rather than switched to argon2id. Switching would mean either
 * a forced reset for everyone or carrying two algorithms indefinitely, and
 * bcrypt at an adequate cost is not the weak link in this system. The upgrade
 * path stays open through {@see needsRehash()}: raising the cost re-hashes
 * each user transparently the next time they sign in.
 */
final class PasswordHasher
{
    /**
     * Work factor. 12 is ~250ms on current hardware — high enough to make
     * offline cracking expensive, low enough that a login is not a DoS vector.
     *
     * Raising this is safe: existing hashes keep verifying and are upgraded on
     * next sign-in. Lowering it does nothing to hashes already stored.
     */
    private const COST = 12;

    /**
     * bcrypt silently truncates at 72 bytes, so a longer password would have
     * its tail ignored. Rejecting is honest; truncating is not.
     */
    private const MAX_LENGTH = 72;

    public function __construct(private readonly int $cost = self::COST)
    {
    }

    /**
     * @throws \InvalidArgumentException on an empty or over-long password
     */
    public function hash(string $plaintext): string
    {
        $this->assertHashable($plaintext);

        $hash = password_hash($plaintext, PASSWORD_BCRYPT, ['cost' => $this->cost]);

        // @phpstan-ignore-next-line defensive: documented to throw, not return false, since PHP 8
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    /**
     * Constant-time verification.
     *
     * Returns false for malformed or empty stored hashes rather than throwing:
     * a corrupt row must fail the login, not the request.
     */
    public function verify(string $plaintext, string $hash): bool
    {
        if ($plaintext === '' || $hash === '') {
            return false;
        }

        // Over-long input cannot have produced any stored hash, and passing it
        // on would compare only its first 72 bytes.
        if (strlen($plaintext) > self::MAX_LENGTH) {
            return false;
        }

        return password_verify($plaintext, $hash);
    }

    /**
     * Whether a verified password should be re-hashed and stored again.
     *
     * Call this immediately after a successful verify, while the plaintext is
     * still in hand — it is the only moment an upgrade is possible.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $this->cost]);
    }

    /**
     * Whether a stored value looks like a bcrypt hash at all.
     *
     * Useful during the migration: a legacy row holding an md5 or sha1 digest
     * must be found and force-reset, never silently accepted.
     */
    public function isSupported(string $hash): bool
    {
        $info = password_get_info($hash);

        return $info['algo'] === PASSWORD_BCRYPT;
    }

    private function assertHashable(string $plaintext): void
    {
        if (trim($plaintext) === '') {
            throw new \InvalidArgumentException('Password must not be empty.');
        }

        if (strlen($plaintext) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Password must be at most %d bytes; bcrypt would silently ignore the rest.',
                self::MAX_LENGTH,
            ));
        }
    }
}
