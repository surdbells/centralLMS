<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Analytics;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Engagement\UserSessionRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Engagement analytics — how much, how often, on what device.
 *
 * GET /v1/analytics/engagement?days=N
 *
 * Active learners (day/week/month), average session length, sign-in frequency
 * and the mobile/desktop/tablet split, over the last N days. Reads the session
 * stream {@see HeartbeatAction} records and the per-sign-in records. Needs
 * `report.view`, the same as the admin dashboard.
 */
final class EngagementAnalyticsAction
{
    public function __construct(
        private readonly UserSessionRepository $sessions,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::ReportView);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::ReportView->value);
        }

        $days = max(1, min(365, (int) ($request->getQueryParams()['days'] ?? 30)));
        $to   = $this->clock->now();
        $from = $to->sub(new \DateInterval('P' . $days . 'D'));
        $t    = $caller->tenantId;

        return Json::ok($response, [
            'from'    => $from->format(\DateTimeInterface::ATOM),
            'to'      => $to->format(\DateTimeInterface::ATOM),
            'days'    => $days,
            'totals'  => $this->sessions->totals($t, $from, $to),
            'login'   => $this->sessions->loginFrequency($t, $from, $to),
            'devices' => $this->sessions->deviceUsage($t, $from, $to),
            'daily'   => $this->sessions->activeSeries($t, $from, $to, 'day'),
            'weekly'  => $this->sessions->activeSeries($t, $from, $to, 'week'),
            'monthly' => $this->sessions->activeSeries($t, $from, $to, 'month'),
        ]);
    }
}
