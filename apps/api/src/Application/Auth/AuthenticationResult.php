<?php

declare(strict_types=1);

namespace Central\Api\Application\Auth;

use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Identity\User;
use Central\Api\Infrastructure\Security\TokenPair;

/**
 * A successful authentication: the tokens, the account, and what it may do.
 *
 * The permission list travels with the sign-in response so the clients can
 * build their navigation in one round trip instead of calling `/me` and then
 * guessing until it arrives.
 */
final readonly class AuthenticationResult
{
    public function __construct(
        public TokenPair $tokens,
        public User $user,
        public AuthenticatedUser $authenticated,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $caller = $this->authenticated->toArray();

        return [
            ...$this->tokens->toArray(),
            'user'        => $this->user->toArray(),
            'roles'       => $caller['roles'],
            'permissions' => $caller['permissions'],
        ];
    }
}
