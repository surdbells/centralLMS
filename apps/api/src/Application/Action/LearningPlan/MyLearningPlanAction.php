<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\LearningPlan;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\LearningPlan\LearningPlanRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The learner's own Learning Plan.
 *
 * GET /v1/me/learning-plans                               the published plans
 *                                                         they are on, by year
 * PUT /v1/me/learning-plans/items/{itemId}/schedule       set/clear a target date
 * PUT /v1/me/learning-plans/items/{itemId}/status         mark an intervention
 *
 * The caller is always the subject; there is no user id in any of these.
 */
final class MyLearningPlanAction
{
    private const INTERVENTION_STATUSES = ['not_scheduled', 'scheduled', 'in_progress', 'completed'];

    public function __construct(private readonly LearningPlanRepository $plans)
    {
    }

    public function mine(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = AuthenticationMiddleware::require($request);

        return Json::ok($response, [
            'plans'      => $this->plans->forLearner((string) $caller->tenantId, (string) $caller->id),
            'completion' => $this->plans->completionForLearner((string) $caller->tenantId, (string) $caller->id),
        ]);
    }

    /** @param array<string, string> $args */
    public function schedule(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $caller = AuthenticationMiddleware::require($request);
        $date   = ((array) ($request->getParsedBody() ?? []))['date'] ?? null;
        $date   = is_string($date) && trim($date) !== '' ? trim($date) : null;

        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw ValidationFailed::fromArray(['date' => 'Use a YYYY-MM-DD date, or clear it.']);
        }

        $this->plans->setSchedule((string) $caller->tenantId, (string) $caller->id, $args['itemId'] ?? '', $date);

        return Json::noContent($response);
    }

    /** @param array<string, string> $args */
    public function setStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $caller = AuthenticationMiddleware::require($request);
        $status = (string) (((array) ($request->getParsedBody() ?? []))['status'] ?? '');

        if (!in_array($status, self::INTERVENTION_STATUSES, true)) {
            throw ValidationFailed::fromArray(['status' => 'Unknown status.']);
        }

        $this->plans->setItemStatus((string) $caller->tenantId, (string) $caller->id, $args['itemId'] ?? '', $status);

        return Json::noContent($response);
    }
}
