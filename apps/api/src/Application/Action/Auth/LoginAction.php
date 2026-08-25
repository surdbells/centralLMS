<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Auth;

use Central\Api\Application\Auth\AuthenticationService;
use Central\Api\Application\Http\Json;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v1/auth/login
 *
 * Returns an access/refresh pair plus the caller's roles and permissions, so
 * the client can render its navigation without a second round trip.
 */
final class LoginAction
{
    public function __construct(private readonly AuthenticationService $auth)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);

        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $violations = [];

        if ($email === '') {
            $violations['email'] = 'Enter your email address.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $violations['email'] = 'Enter a valid email address.';
        }

        if ($password === '') {
            $violations['password'] = 'Enter your password.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $server = $request->getServerParams();

        $result = $this->auth->login(
            $email,
            $password,
            // Optional: a deployment resolving tenants by subdomain sends this
            // so the same email can hold accounts in several organisations.
            isset($body['tenantId']) ? (string) $body['tenantId'] : null,
            $request->getHeaderLine('User-Agent') ?: null,
            isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null,
        );

        return Json::ok($response, $result->toArray());
    }
}
