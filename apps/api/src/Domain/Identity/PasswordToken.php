<?php

declare(strict_types=1);

namespace Central\Api\Domain\Identity;

/**
 * A single-use secret that authorises setting one password.
 *
 * The secret is generated once, handed to whoever is being emailed, and then
 * only its hash is kept. Nothing — not this object, not the database, not a
 * response body — can produce the secret again afterwards.
 *
 * The legacy did the opposite: `authentication/confirm` returned the one-time
 * code to the browser, which posted it back for the server to compare against
 * the value it had just sent. That is not a check.
 */
final readonly class PasswordToken
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $userId,
        public PasswordTokenPurpose $purpose,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $usedAt = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * Turns a secret into what is stored.
     *
     * SHA-256 without a salt, deliberately: the input is 256 bits of entropy
     * from the system's own generator, not something a person chose, so there
     * is no dictionary to defend against and the lookup has to be by exact
     * value. A password hash here would mean scanning every live row.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * A fresh secret, URL-safe.
     *
     * Long enough that guessing is not a strategy, and no `+`, `/` or `=` so
     * it survives being pasted out of an email into an address bar.
     */
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function hasExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /** Whether it may still be redeemed. */
    public function isLive(\DateTimeImmutable $now): bool
    {
        return !$this->isUsed() && !$this->hasExpired($now);
    }
}
