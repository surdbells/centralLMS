<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Auth;

use Central\Api\Application\Auth\AuthenticationService;
use Central\Api\Application\Http\Json;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v1/auth/refresh
 *
 * Public by design: the refresh token *is* the credential, so requiring a
 * valid access token here would make refresh useless — the whole point is to
 * be called once the access token has expired.
 */
final class RefreshAction
{
    public function __construct(private readonly AuthenticationService $auth)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $token = trim((string) ($body['refreshToken'] ?? ''));

        if ($token === '') {
            throw ValidationFailed::fromArray(['refreshToken' => 'A refresh token is required.']);
        }

        $server = $request->getServerParams();

        $result = $this->auth->refresh(
            $token,
            $request->getHeaderLine('User-Agent') ?: null,
            isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null,
        );

        return Json::ok($response, $result->toArray());
    }
}
