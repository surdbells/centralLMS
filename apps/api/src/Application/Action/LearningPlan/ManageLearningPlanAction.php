<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\LearningPlan;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\LearningPlan\LearningPlan;
use Central\Api\Domain\LearningPlan\LearningPlanRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Administering per-year learning plans.
 *
 * GET/POST   /v1/learning-plans
 * GET/PUT/DELETE /v1/learning-plans/{id}  · PATCH /{id}/status
 * GET/POST   /v1/learning-plans/{id}/items · DELETE /{id}/items/{itemId}
 * GET/POST   /v1/learning-plans/{id}/members · DELETE /{id}/members/{userId}
 *
 * Needs `course.manage` — a plan is content assigned to people.
 */
final class ManageLearningPlanAction
{
    public function __construct(private readonly LearningPlanRepository $plans)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $year   = $request->getQueryParams()['year'] ?? null;

        $list = $this->plans->list($tenant, $year === null || $year === '' ? null : (int) $year);

        return Json::ok($response, array_map(static fn (LearningPlan $p): array => $p->toArray(), $list));
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $plan   = LearningPlan::fromInput('', $tenant, (array) ($request->getParsedBody() ?? []));

        return Json::created($response, $this->plans->save($plan)->toArray());
    }

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $plan   = $this->plans->find($tenant, $args['id'] ?? '');

        if ($plan === null) {
            throw new EntityNotFound('Learning plan', $args['id'] ?? '');
        }

        return Json::ok($response, $plan->toArray() + ['items' => $this->plans->items($tenant, $plan->id)]);
    }

    /** @param array<string, string> $args */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $id     = $args['id'] ?? '';

        if ($this->plans->find($tenant, $id) === null) {
            throw new EntityNotFound('Learning plan', $id);
        }

        $plan = LearningPlan::fromInput($id, $tenant, (array) ($request->getParsedBody() ?? []));

        return Json::ok($response, $this->plans->save($plan)->toArray());
    }

    /** @param array<string, string> $args */
    public function setStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $status = (string) (((array) ($request->getParsedBody() ?? []))['status'] ?? '');
        $plan   = $this->plans->setStatus($tenant, $args['id'] ?? '', $status);

        if ($plan === null) {
            throw new EntityNotFound('Learning plan', $args['id'] ?? '');
        }

        return Json::ok($response, $plan->toArray());
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->plans->delete($this->tenant($request), $args['id'] ?? '');

        return Json::noContent($response);
    }

    // --- items ----------------------------------------------------------------

    /** @param array<string, string> $args */
    public function items(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::ok($response, $this->plans->items($this->tenant($request), $args['id'] ?? ''));
    }

    /** @param array<string, string> $args */
    public function saveItem(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $item = $this->plans->saveItem(
            $this->tenant($request),
            $args['id'] ?? '',
            (array) ($request->getParsedBody() ?? []),
        );

        return Json::ok($response, $item);
    }

    /** @param array<string, string> $args */
    public function deleteItem(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->plans->deleteItem($this->tenant($request), $args['id'] ?? '', $args['itemId'] ?? '');

        return Json::noContent($response);
    }

    // --- members --------------------------------------------------------------

    /** @param array<string, string> $args */
    public function members(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $query = $request->getQueryParams();

        return Json::ok($response, $this->plans->members(
            $this->tenant($request),
            $args['id'] ?? '',
            max(1, (int) ($query['page'] ?? 1)),
            (int) ($query['perPage'] ?? 50),
        ));
    }

    /**
     * Assign people: {userIds?: string[], departmentId?: string, everyone?: bool}.
     *
     * @param array<string, string> $args
     */
    public function assign(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $planId = $args['id'] ?? '';
        $body   = (array) ($request->getParsedBody() ?? []);

        $assigned = 0;

        if (filter_var($body['everyone'] ?? false, FILTER_VALIDATE_BOOL)) {
            $assigned += $this->plans->assignEveryone($tenant, $planId);
        }

        if (is_string($body['departmentId'] ?? null) && $body['departmentId'] !== '') {
            $assigned += $this->plans->assignDepartment($tenant, $planId, (string) $body['departmentId']);
        }

        if (is_array($body['userIds'] ?? null)) {
            /** @var list<string> $ids */
            $ids = array_values(array_filter($body['userIds'], static fn ($u): bool => is_string($u)));
            $assigned += $this->plans->assignMembers($tenant, $planId, $ids);
        }

        return Json::ok($response, ['assigned' => $assigned]);
    }

    /** @param array<string, string> $args */
    public function removeMember(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->plans->removeMember($this->tenant($request), $args['id'] ?? '', $args['userId'] ?? '');

        return Json::noContent($response);
    }

    // --- plumbing -------------------------------------------------------------

    private function tenant(ServerRequestInterface $request): string
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::CourseManage);

        return (string) $caller->tenantId;
    }
}
