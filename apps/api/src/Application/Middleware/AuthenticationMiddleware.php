<?php

declare(strict_types=1);

namespace Central\Api\Application\Middleware;

use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Exception\AuthenticationFailed;
use Central\Api\Infrastructure\Security\TokenAuthenticator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Verifies the bearer token and attaches the caller to the request.
 *
 * Applied to the protected route group as a whole rather than per route, so
 * adding an endpoint inside that group cannot accidentally ship unauthenticated
 * — the failure mode of per-controller auth checks, and the reason the legacy
 * backend had public endpoints nobody knew about.
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'user';

    public function __construct(private readonly TokenAuthenticator $authenticator)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->authenticator->authenticate($this->extractToken($request));

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $user));
    }

    /**
     * Convenience accessor for actions. Throws rather than returning null so
     * that callers inside a protected group need no null check.
     *
     * @throws AuthenticationFailed
     */
    public static function require(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(self::ATTRIBUTE);

        if (!$user instanceof AuthenticatedUser) {
            throw new AuthenticationFailed(reason: 'no_user_on_request');
        }

        return $user;
    }

    public static function optional(ServerRequestInterface $request): ?AuthenticatedUser
    {
        $user = $request->getAttribute(self::ATTRIBUTE);

        return $user instanceof AuthenticatedUser ? $user : null;
    }

    private function extractToken(ServerRequestInterface $request): string
    {
        $header = $request->getHeaderLine('Authorization');

        if ($header === '') {
            throw new AuthenticationFailed(reason: 'missing_authorization_header');
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
            throw new AuthenticationFailed(reason: 'malformed_authorization_header');
        }

        return $matches[1];
    }
}
