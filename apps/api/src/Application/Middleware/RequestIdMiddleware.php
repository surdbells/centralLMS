<?php

declare(strict_types=1);

namespace Central\Api\Application\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Gives every request a correlation id, echoed back in `X-Request-Id`.
 *
 * The web, saas and mobile clients each send their own id; when they do we
 * keep it, so a support ticket that quotes an id from the browser console can
 * be traced straight through the API logs and Sentry.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'requestId';
    private const HEADER   = 'X-Request-Id';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $this->readIncoming($request) ?? Uuid::uuid4()->toString();

        $request = $request->withAttribute(self::ATTRIBUTE, $requestId);

        return $handler->handle($request)->withHeader(self::HEADER, $requestId);
    }

    /**
     * A client-supplied id is only honoured if it is short and boring — it
     * ends up in log files, so it must not be an injection vector.
     */
    private function readIncoming(ServerRequestInterface $request): ?string
    {
        $candidate = trim($request->getHeaderLine(self::HEADER));

        if ($candidate === '' || strlen($candidate) > 64) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._-]+$/', $candidate) === 1 ? $candidate : null;
    }
}
