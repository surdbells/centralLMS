<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\AssessmentRepository;
use Central\Api\Domain\Assessment\AttemptRepository;
use Central\Api\Domain\Assessment\QuestionRepository;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/assessments/dashboard
 *
 * The admin dashboard payload in one call: headline scalars, a trailing-6-month
 * trend, a recent-activity feed and a per-difficulty breakdown. The `range`
 * (7d/30d/90d/1y, default 30d) rescopes the window-scoped figures; the trend is
 * always the last six calendar months.
 */
final class AssessmentDashboardAction extends AssessmentAction
{
    private const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90, '1y' => 365];

    public function __construct(
        AssessmentRepository $assessments,
        QuestionRepository $questions,
        AttemptRepository $attempts,
        private readonly Clock $clock,
    ) {
        parent::__construct($assessments, $questions, $attempts);
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $range = (string) ($request->getQueryParams()['range'] ?? '30d');
        $days = self::RANGES[$range] ?? 30;

        $now = $this->clock->now();
        $from = $now->modify('-' . $days . ' days');

        return Json::ok($response, $this->assessments->dashboard($this->tenantId($request), $now, $from));
    }
}
