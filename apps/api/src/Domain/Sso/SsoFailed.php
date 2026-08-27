<?php

declare(strict_types=1);

namespace Central\Api\Domain\Sso;

use Central\Api\Domain\Exception\DomainException;

/**
 * Signing in from a directory did not work.
 *
 * Every message here is written to be read by whoever is trying to sign in,
 * which is why none of them name a client id, a redirect URI or a claim. Those
 * go to the log, where the person who can act on them will look — telling
 * somebody at a sign-in screen that the audience did not match helps nobody,
 * and telling an attacker which check failed helps them.
 */
final class SsoFailed extends DomainException
{
    public function errorCode(): string
    {
        return 'sso_failed';
    }
}
