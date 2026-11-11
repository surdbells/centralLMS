<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Analytics;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Analytics\AnalyticsRepository;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Engagement\UserSessionRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Feedback\BehaviourFeedbackRepository;
use Central\Api\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The cross-cutting figures the admin Analytics page needs that the admin
 * dashboard and the engagement endpoint do not already carry: the compliance
 * three-way (Completed / In Progress / Yet to Attempt), adoption and
 * activation, and tenant-wide course satisfaction.
 *
 * GET /v1/analytics/summary?days=N — needs `report.view`.
 */
final class AnalyticsSummaryAction
{
    public function __construct(
        private readonly AnalyticsRepository $analytics,
        private readonly UserSessionRepository $sessions,
        private readonly BehaviourFeedbackRepository $feedback,
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

        $adoption = $this->analytics->adoption($t, $from);

        // Active-learner rate needs both the session stream (who was active) and
        // the headcount (out of how many), so it is computed here where both are
        // in hand rather than in either repository alone.
        $active = $this->sessions->totals($t, $from, $to)['learners'];
        $adoption['activeLearners']    = $active;
        $adoption['activeLearnerRate'] = $adoption['people'] > 0
            ? round($active / $adoption['people'] * 100, 1)
            : 0.0;

        return Json::ok($response, [
            'days'         => $days,
            'compliance'   => $this->analytics->complianceStatus($t),
            'adoption'     => $adoption,
            'satisfaction' => $this->analytics->satisfaction($t),
            'behaviour'    => $this->feedback->summary($t),
        ]);
    }
}
