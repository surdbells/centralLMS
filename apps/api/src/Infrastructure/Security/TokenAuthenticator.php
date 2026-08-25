<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Security;

use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Exception\AuthenticationFailed;

/**
 * Turns a bearer token into an {@see AuthenticatedUser}.
 *
 * Two steps, deliberately separate: {@see JwtTokenService} proves the token is
 * genuine and unexpired, then {@see RoleRepository} resolves what the holder
 * may currently do. Permissions are never read from the token body — a token
 * minted before a role was edited must not carry the old grants.
 */
final class TokenAuthenticator
{
    public function __construct(
        private readonly JwtTokenService $tokens,
        private readonly RoleRepository $roles,
    ) {
    }

    /**
     * @throws AuthenticationFailed
     */
    public function authenticate(string $token): AuthenticatedUser
    {
        $claims = $this->tokens->verifyAccessToken($token);

        $tenantId = isset($claims['tid']) ? (string) $claims['tid'] : null;

        $identifiers = [];
        foreach ((array) ($claims['roles'] ?? []) as $identifier) {
            if (is_scalar($identifier) && (string) $identifier !== '') {
                $identifiers[] = (string) $identifier;
            }
        }

        // findForTenant drops ids belonging to another tenant and ids that no
        // longer exist, so a tampered or stale token loses those roles rather
        // than gaining anything.
        $roles = $this->roles->findForTenant($tenantId, $identifiers);

        return new AuthenticatedUser(
            id: (string) ($claims['sub'] ?? ''),
            tenantId: $tenantId,
            email: (string) ($claims['email'] ?? ''),
            roles: $roles,
            tokenId: (string) ($claims['jti'] ?? ''),
        );
    }
}
