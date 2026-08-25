<?php

declare(strict_types=1);

namespace Central\Api\Domain\Identity;

/**
 * Account lifecycle.
 *
 * The legacy schema spread this across `is_active`, `account_locked`,
 * `approved`, `suspended` and `deleted_at` — five columns that could disagree,
 * and did. One column with a check constraint cannot.
 */
enum UserStatus: string
{
    /** Created but has never set a password. */
    case Invited = 'invited';

    case Active = 'active';

    /** Switched off by an administrator. */
    case Suspended = 'suspended';

    /** Locked by the system, typically after repeated failed sign-ins. */
    case Locked = 'locked';

    public function canSignIn(): bool
    {
        return $this === self::Active;
    }

    /**
     * Message shown when sign-in is refused.
     *
     * Deliberately vague for Suspended and Locked: telling an attacker which
     * of the two applies confirms the account exists.
     */
    public function signInRefusalReason(): string
    {
        return match ($this) {
            self::Active    => '',
            self::Invited   => 'This account has not been activated yet. Check your email for the invitation.',
            default         => 'This account is not available. Contact your administrator.',
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
