<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Analytics;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Engagement\UserSessionRepository;
use Central\Api\Support\DeviceDetector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "I am still here."
 *
 * POST /v1/me/session/ping
 *
 * The client calls this while the app is open. A ping within the idle window
 * extends the caller's current session; a later one opens a new one. This is
 * what gives engagement analytics a session to measure — how long people spend
 * and on what device — which a sign-in record alone cannot.
 */
final class HeartbeatAction
{
    /** A gap longer than this ends a session; the next ping starts a new one. */
    private const IDLE_MINUTES = 30;

    public function __construct(private readonly UserSessionRepository $sessions)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = AuthenticationMiddleware::require($request);

        if ($caller->tenantId === null) {
            // Nothing to attribute a session to; accept the ping quietly.
            return Json::ok($response, ['sessionId' => null]);
        }

        $server    = $request->getServerParams();
        $userAgent = $request->getHeaderLine('User-Agent') ?: null;

        $id = $this->sessions->touch(
            $caller->tenantId,
            $caller->id,
            DeviceDetector::classify($userAgent),
            $userAgent,
            isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null,
            self::IDLE_MINUTES,
        );

        return Json::ok($response, ['sessionId' => $id]);
    }
}
