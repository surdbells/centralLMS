<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Analytics;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Engagement\UserSessionRepository;
use Central\Api\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The caller's own engagement, for their report: how much they use the LMS, how
 * often they sign in, and on what devices.
 *
 * GET /v1/me/engagement?days=N — the caller is the subject; no permission beyond
 * being signed in.
 */
final class MyEngagementAction
{
    public function __construct(
        private readonly UserSessionRepository $sessions,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = AuthenticationMiddleware::require($request);

        if ($caller->tenantId === null) {
            return Json::ok($response, ['sessions' => 0, 'averageMinutes' => 0, 'signIns' => 0, 'devices' => []]);
        }

        $days = max(1, min(365, (int) ($request->getQueryParams()['days'] ?? 30)));
        $to   = $this->clock->now();
        $from = $to->sub(new \DateInterval('P' . $days . 'D'));

        return Json::ok(
            $response,
            $this->sessions->forUser($caller->tenantId, $caller->id, $from, $to) + ['days' => $days],
        );
    }
}
