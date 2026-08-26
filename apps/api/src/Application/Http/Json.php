<?php

declare(strict_types=1);

namespace Central\Api\Application\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * The single place that writes a JSON body.
 *
 * The legacy backend had `echo json_encode(...)` in over a hundred files, each
 * with a slightly different envelope, so no client could handle errors
 * generically. Every response now has exactly one of two shapes:
 *
 *   success: {"data": …, "meta": {…}?}
 *   failure: {"error": {"code": "…", "message": "…", "details": {…}?}}
 */
final class Json
{
    private const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param array<string, mixed> $meta
     */
    public static function ok(
        ResponseInterface $response,
        mixed $data,
        int $status = 200,
        array $meta = [],
    ): ResponseInterface {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return self::write($response, $payload, $status);
    }

    /**
     * Paginated collection. `meta` carries everything a client needs to render
     * pagination without a second request.
     *
     * @param list<mixed>          $items
     * @param array<string, mixed> $extraMeta anything else the page needs alongside
     *                                        it — the unread count with an inbox,
     *                                        so a badge and its list cannot
     *                                        disagree
     */
    public static function page(
        ResponseInterface $response,
        array $items,
        int $total,
        int $page,
        int $perPage,
        array $extraMeta = [],
    ): ResponseInterface {
        return self::write($response, [
            'data' => $items,
            'meta' => [
                'total'      => $total,
                'page'       => $page,
                'perPage'    => $perPage,
                'totalPages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
                ...$extraMeta,
            ],
        ], 200);
    }

    public static function created(ResponseInterface $response, mixed $data, ?string $location = null): ResponseInterface
    {
        $response = self::ok($response, $data, 201);

        return $location === null ? $response : $response->withHeader('Location', $location);
    }

    public static function noContent(ResponseInterface $response): ResponseInterface
    {
        return $response->withStatus(204);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(
        ResponseInterface $response,
        int $status,
        string $code,
        string $message,
        array $details = [],
    ): ResponseInterface {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return self::write($response, ['error' => $error], $status);
    }

    private static function write(ResponseInterface $response, mixed $payload, int $status): ResponseInterface
    {
        $encoded = json_encode($payload, self::ENCODE_FLAGS | JSON_THROW_ON_ERROR);

        $response->getBody()->write($encoded);

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }
}
