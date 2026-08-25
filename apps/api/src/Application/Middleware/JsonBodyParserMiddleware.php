<?php

declare(strict_types=1);

namespace Central\Api\Application\Middleware;

use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Decodes JSON request bodies into the parsed body.
 *
 * Slim's built-in parser silently yields null on malformed JSON, which then
 * surfaces much later as a confusing "field is required" error. This one fails
 * loudly and immediately with a 422 that names the actual problem.
 */
final class JsonBodyParserMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (!str_contains(strtolower($contentType), 'application/json')) {
            return $handler->handle($request);
        }

        $body = (string) $request->getBody();

        if (trim($body) === '') {
            return $handler->handle($request->withParsedBody([]));
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ValidationFailed::fromArray([
                '_body' => 'Request body is not valid JSON: ' . $e->getMessage(),
            ]);
        }

        if (!is_array($decoded)) {
            throw ValidationFailed::fromArray([
                '_body' => 'Request body must be a JSON object or array.',
            ]);
        }

        return $handler->handle($request->withParsedBody($decoded));
    }
}
