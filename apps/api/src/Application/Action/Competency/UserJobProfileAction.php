<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Competency;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Competency\CompetencyRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The job competency profiles an employee is assigned to.
 *
 * Assignment is what makes a new cycle auto-start an assessment for a person,
 * so it sits with the rest of the competency exercise: reading needs
 * `competency.view`, changing needs `competency.assess`. A person may hold more
 * than one profile.
 *
 * GET /v1/users/{id}/job-profiles
 * PUT /v1/users/{id}/job-profiles   { profileIds: [...] }
 */
final class UserJobProfileAction
{
    public function __construct(private readonly CompetencyRepository $repository)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CompetencyView);

        return Json::ok($response, [
            'profiles' => $this->repository->profilesForUser($this->tenantId($request), $args['id'] ?? ''),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CompetencyAssess);

        $tenantId = $this->tenantId($request);
        $userId   = $args['id'] ?? '';

        $body = (array) ($request->getParsedBody() ?? []);
        $raw  = is_array($body['profileIds'] ?? null) ? $body['profileIds'] : [];

        $ids = [];
        foreach ($raw as $value) {
            $id = is_string($value) ? trim($value) : '';
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        $this->repository->setUserProfiles($tenantId, $userId, $ids);

        return Json::ok($response, [
            'profiles' => $this->repository->profilesForUser($tenantId, $userId),
        ]);
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::CompetencyView->value);
        }

        return $user->tenantId;
    }
}
