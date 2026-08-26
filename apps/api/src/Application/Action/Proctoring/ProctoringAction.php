<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Proctoring;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Assessment\Assessment;
use Central\Api\Domain\Assessment\AssessmentRepository;
use Central\Api\Domain\Assessment\Attempt;
use Central\Api\Domain\Assessment\AttemptRepository;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Proctoring\ModuleEntitlements;
use Central\Api\Domain\Proctoring\ProctoringRepository;
use Central\Api\Domain\Proctoring\ProctoringSession;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for the proctoring endpoints.
 *
 * Everything here is gated twice: the tenant must be entitled to the module,
 * and the caller must hold the permission. They answer different questions —
 * "has this organisation bought it" and "may this person do it" — and both
 * have to hold.
 */
abstract class ProctoringAction
{
    public function __construct(
        protected readonly ProctoringRepository $proctoring,
        protected readonly AssessmentRepository $assessments,
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
            throw new AccessDenied(Permission::ProctoringConfigure->value);
        }

        return $user->tenantId;
    }

    final protected function userId(ServerRequestInterface $request): string
    {
        return $this->caller($request)->id;
    }

    /**
     * The tenant must have the module, and must have it before anything else
     * is checked.
     *
     * A 409 rather than a 403: nothing is wrong with the caller, and telling
     * an administrator "you lack permission" when the answer is "your plan
     * does not include this" sends them to the wrong person.
     *
     * @throws ConflictingState
     */
    final protected function mustBeEntitled(ServerRequestInterface $request): string
    {
        $tenantId = $this->tenantId($request);

        if (!$this->proctoring->entitlementsFor($tenantId)->has(ModuleEntitlements::PROCTORING)) {
            throw new ConflictingState(
                'Proctoring is not included in this plan.',
                'module_not_entitled',
            );
        }

        return $tenantId;
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
    final protected function requireAttempt(ServerRequestInterface $request, string $id): Attempt
    {
        return $this->attempts->find($this->tenantId($request), $id)
            ?? throw new EntityNotFound('Attempt', $id);
    }

    /**
     * The attempt must be the caller's own.
     *
     * Used by the endpoints a learner's own browser calls: reporting a
     * violation against someone else's sitting would be a way to sabotage it.
     *
     * @throws AccessDenied
     */
    final protected function mustOwn(ServerRequestInterface $request, Attempt $attempt): void
    {
        if ($attempt->userId !== $this->userId($request)) {
            throw new AccessDenied(Permission::AssessmentTake->value);
        }
    }

    /**
     * @throws EntityNotFound
     */
    final protected function requireSession(string $tenantId, string $attemptId): ProctoringSession
    {
        return $this->proctoring->findSessionForAttempt($tenantId, $attemptId)
            ?? throw new EntityNotFound('Proctoring session', $attemptId);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }
}
