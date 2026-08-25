<?php

declare(strict_types=1);

namespace Central\Api\Application\Action;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who the current access token says you are, and what it lets you do.
 *
 * The clients call this once after sign-in and drive their whole navigation
 * from the returned permission list, so a menu item is never rendered for an
 * action the API would reject.
 */
final class MeAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::ok($response, AuthenticationMiddleware::require($request)->toArray());
    }
}
