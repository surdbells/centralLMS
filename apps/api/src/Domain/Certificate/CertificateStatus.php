<?php

declare(strict_types=1);

namespace Central\Api\Domain\Certificate;

/**
 * Whether a certificate still stands.
 */
enum CertificateStatus: string
{
    case Active = 'active';

    /** Withdrawn deliberately — issued in error, or the holder was found out. */
    case Revoked = 'revoked';

    /** Past its validity date. */
    case Expired = 'expired';

    /** Replaced by a later certificate for the same thing. */
    case Superseded = 'superseded';

    public static function fromLegacy(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Active;
    }

    /**
     * Whether a verifier should be told it is good.
     *
     * Only `active`. Superseded is deliberately not valid: the holder has a
     * newer one, and letting an old certificate verify would let someone
     * present the version with the better score.
     */
    public function isValid(): bool
    {
        return $this === self::Active;
    }

    /**
     * What to tell whoever is checking.
     *
     * Each case says something different and useful. Collapsing them into
     * "not valid" would leave an employer unable to tell a forgery from a
     * lapsed renewal.
     */
    public function verificationMessage(): string
    {
        return match ($this) {
            self::Active     => 'This certificate is valid.',
            self::Revoked    => 'This certificate has been withdrawn by the issuer.',
            self::Expired    => 'This certificate has expired.',
            self::Superseded => 'This certificate has been replaced by a more recent one.',
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
