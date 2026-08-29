<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Competency;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Competency\CompetencyRepository;
use Central\Api\Domain\Competency\JobCompetencyProfile;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Job Competency Profiles — the standard each role is measured against.
 */
final class JobProfileAction
{
    public function __construct(private readonly CompetencyRepository $repository)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CompetencyView);

        $query = $request->getQueryParams();
        [$page, $perPage] = $this->paging($request);

        $result = $this->repository->profiles(
            $this->tenantId($request),
            $this->str($query['function'] ?? null),
            $this->str($query['search'] ?? null),
            $page,
            $perPage,
        );

        return Json::page(
            $response,
            array_map(static fn (JobCompetencyProfile $p): array => $p->toArray(), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CompetencyView);

        $profile = $this->repository->findProfile($this->tenantId($request), $args['id'] ?? '');

        if ($profile === null) {
            throw new EntityNotFound('Job competency profile', $args['id'] ?? '');
        }

        return Json::ok($response, $profile->toArray());
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CompetencyManage);

        $tenantId = $this->tenantId($request);
        $profile = JobCompetencyProfile::fromInput($this->uuid(), $tenantId, $this->body($request));

        try {
            $saved = $this->repository->saveProfile($profile);
        } catch (UniqueConstraintViolationException) {
            throw new ConflictingState(
                'A profile for that job already exists in this function.',
                'duplicate_profile',
            );
        }

        return Json::created($response, $saved->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CompetencyManage);

        $tenantId = $this->tenantId($request);
        $id = $args['id'] ?? '';

        if ($this->repository->findProfile($tenantId, $id) === null) {
            throw new EntityNotFound('Job competency profile', $id);
        }

        $profile = JobCompetencyProfile::fromInput($id, $tenantId, $this->body($request));

        try {
            $saved = $this->repository->saveProfile($profile);
        } catch (UniqueConstraintViolationException) {
            throw new ConflictingState(
                'Another profile in this function already has that job title.',
                'duplicate_profile',
            );
        }

        return Json::ok($response, $saved->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CompetencyManage);

        if (!$this->repository->deleteProfile($this->tenantId($request), $args['id'] ?? '')) {
            throw new EntityNotFound('Job competency profile', $args['id'] ?? '');
        }

        return Json::noContent($response);
    }

    // --- internals ------------------------------------------------------------

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

    /**
     * @return array{int, int}
     */
    private function paging(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            max(1, (int) ($query['page'] ?? 1)),
            max(1, min(200, (int) ($query['perPage'] ?? 50))),
        ];
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff),
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }
}
