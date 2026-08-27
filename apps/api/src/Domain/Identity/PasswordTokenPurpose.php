<?php

declare(strict_types=1);

namespace Central\Api\Domain\Identity;

/**
 * Why a password token was issued.
 *
 * The two differ in how long they last and what redeeming one does besides
 * setting the password — an invitation also turns an account that could not be
 * signed into at all into one that can.
 */
enum PasswordTokenPurpose: string
{
    case Reset = 'reset';
    case Invitation = 'invitation';

    /**
     * How long it stays live.
     *
     * A reset is short because the person asked for it a moment ago and is
     * waiting. An invitation is long because it may arrive while somebody is
     * on leave, and an expired invitation means an administrator has to notice
     * and send another.
     */
    public function lifetime(): \DateInterval
    {
        return new \DateInterval(match ($this) {
            self::Reset      => 'PT1H',
            self::Invitation => 'P14D',
        });
    }

    public function label(): string
    {
        return match ($this) {
            self::Reset      => 'Password reset',
            self::Invitation => 'Invitation',
        };
    }
}
