<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Auth;

use Central\Api\Application\Auth\AuthenticationService;
use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v1/auth/logout
 *
 * Revokes the supplied refresh token, or every session for the caller when
 * `allDevices` is set.
 *
 * Always returns 204, even for a token that was already revoked or never
 * existed. Signing out must not fail, and reporting "unknown token" would let
 * an unauthenticated caller probe which tokens are live.
 */
final class LogoutAction
{
    public function __construct(private readonly AuthenticationService $auth)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);

        if (filter_var($body['allDevices'] ?? false, FILTER_VALIDATE_BOOL)) {
            $this->auth->logoutEverywhere(AuthenticationMiddleware::require($request)->id);

            return Json::noContent($response);
        }

        $this->auth->logout(trim((string) ($body['refreshToken'] ?? '')));

        return Json::noContent($response);
    }
}
