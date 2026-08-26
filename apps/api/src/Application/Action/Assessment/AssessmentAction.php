<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Assessment\Assessment;
use Central\Api\Domain\Assessment\AssessmentRepository;
use Central\Api\Domain\Assessment\AttemptRepository;
use Central\Api\Domain\Assessment\QuestionBank;
use Central\Api\Domain\Assessment\QuestionRepository;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for the assessment endpoints.
 */
abstract class AssessmentAction
{
    public function __construct(
        protected readonly AssessmentRepository $assessments,
        protected readonly QuestionRepository $questions,
        protected readonly AttemptRepository $attempts,
    ) {
    }

    final protected function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    /**
     * @throws AccessDenied
     */
    final protected function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::AssessmentTake->value);
        }

        return $user->tenantId;
    }

    final protected function userId(ServerRequestInterface $request): string
    {
        return $this->caller($request)->id;
    }

    /**
     * @throws EntityNotFound
     */
    final protected function requireAssessment(ServerRequestInterface $request, string $id): Assessment
    {
        return $this->assessments->find($this->tenantId($request), $id)
            ?? throw new EntityNotFound('Assessment', $id);
    }

    /**
     * @throws EntityNotFound
     */
    final protected function requireBank(ServerRequestInterface $request, string $id): QuestionBank
    {
        return $this->questions->findBank($this->tenantId($request), $id)
            ?? throw new EntityNotFound('Question bank', $id);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }

    /**
     * @return array{page: int, perPage: int, search: ?string}
     */
    final protected function listing(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            'page'    => max(1, (int) ($query['page'] ?? 1)),
            'perPage' => max(1, min(100, (int) ($query['perPage'] ?? 20))),
            'search'  => isset($query['search']) ? (string) $query['search'] : null,
        ];
    }
}
