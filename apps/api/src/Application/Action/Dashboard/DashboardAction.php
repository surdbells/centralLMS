<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Dashboard;

use Central\Api\Application\Dashboard\AdminDashboardAssembler;
use Central\Api\Application\Dashboard\LearnerDashboardAssembler;
use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Dashboard\ActivityWindow;
use Central\Api\Domain\Dashboard\DashboardPeriod;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Report\ReportCriteria;
use Central\Api\Domain\Report\ReportScope;
use Central\Api\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The two dashboards.
 *
 * GET /v1/dashboards/admin?period=30
 * GET /v1/dashboards/me?activityDays=30&activityUntil=2026-08-27
 *
 * Both took `tenant_id` from the request body, and the learner one took
 * `user_id` too — with `verify_user()` returning 100 unconditionally, that is
 * two ids a caller chooses for themselves. Here the admin dashboard is built
 * from a {@see ReportScope} derived from the token, and the learner one is
 * about the caller and takes no id at all.
 */
final class DashboardAction
{
    public function __construct(
        private readonly AdminDashboardAssembler $admin,
        private readonly LearnerDashboardAssembler $learner,
        private readonly Clock $clock,
    ) {
    }

    public function forAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::ReportView);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::ReportView->value);
        }

        $period = DashboardPeriod::fromQuery($request->getQueryParams(), $this->clock->now());
        $scope  = ReportScope::forCaller($caller, $caller->tenantId);

        // The window is the dashboard's, not the caller's: a period is chosen
        // from a fixed set, and every figure on the page has to be measured
        // over the same one.
        $input = [
            ...$request->getQueryParams(),
            'from' => $period->from->format('Y-m-d'),
            'to'   => $period->to->format('Y-m-d'),
        ];

        $violations = [];
        $criteria   = ReportCriteria::fromInput($scope, $input, $violations);

        if ($criteria === null) {
            throw ValidationFailed::fromArray($violations);
        }

        return Json::ok($response, $this->admin->assemble($criteria, $period));
    }

    /**
     * The caller's own dashboard. No id in, no id to get wrong.
     */
    public function forMe(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = AuthenticationMiddleware::require($request);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::EnrollmentSelf->value);
        }

        return Json::ok($response, $this->learner->assemble(
            $caller->tenantId,
            $caller->id,
            ActivityWindow::fromQuery($request->getQueryParams(), $this->clock->now()),
        ));
    }
}
