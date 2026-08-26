<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Ilt;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Ilt\LiveTraining;
use Central\Api\Domain\Ilt\SessionScheduler;
use Central\Api\Domain\Ilt\TrainingRepository;
use Central\Api\Domain\Ilt\TrainingSession;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for the instructor-led training endpoints.
 */
abstract class TrainingAction
{
    public function __construct(
        protected readonly TrainingRepository $trainings,
        protected readonly SessionScheduler $scheduler,
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
            throw new AccessDenied(Permission::IltSessionView->value);
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
    final protected function requireTraining(ServerRequestInterface $request, string $id): LiveTraining
    {
        return $this->trainings->find($this->tenantId($request), $id)
            ?? throw new EntityNotFound('Training', $id);
    }

    /**
     * A session, resolved through the programme in the path.
     *
     * @throws EntityNotFound
     */
    final protected function requireSession(
        ServerRequestInterface $request,
        string $trainingId,
        string $sessionId,
    ): TrainingSession {
        // The programme is resolved first, so a session id from another
        // programme cannot be reached even inside the right tenant.
        $training = $this->requireTraining($request, $trainingId);
        $session = $this->trainings->findSession($this->tenantId($request), $sessionId);

        if ($session === null || $session->trainingId !== $training->id) {
            throw new EntityNotFound('Session', $sessionId);
        }

        return $session;
    }

    /**
     * @throws ConflictingState
     */
    final protected function mustBeEditable(LiveTraining $training): void
    {
        if (!$training->status->isEditable()) {
            throw new ConflictingState(
                'A completed or cancelled programme is a record, not a plan.',
                'training_not_editable',
            );
        }
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

    /**
     * Whether the caller may see the host URL and the register.
     *
     * The host URL starts the meeting: whoever holds it can admit strangers,
     * mute people and end the session. It is not part of a learner's payload.
     */
    final protected function isOrganiser(ServerRequestInterface $request): bool
    {
        return $this->caller($request)->can(Permission::IltSessionManage);
    }
}
