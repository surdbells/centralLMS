<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Ilt;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Ilt\LiveTraining;
use Central\Api\Domain\Ilt\TrainingSession;
use Central\Api\Domain\Ilt\TrainingStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * Training programmes.
 *
 * GET    /v1/trainings          the catalogue; drafts only for organisers
 * POST   /v1/trainings
 * GET    /v1/trainings/{id}     with its sessions
 * PUT    /v1/trainings/{id}
 * PATCH  /v1/trainings/{id}/status
 * DELETE /v1/trainings/{id}
 */
final class ManageTrainingsAction extends TrainingAction
{
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::IltSessionView);

        $listing = $this->listing($request);
        $query = $request->getQueryParams();
        $organiser = $this->isOrganiser($request);

        $result = $this->trainings->paginate(
            $this->tenantId($request),
            $listing['page'],
            $listing['perPage'],
            $listing['search'],
            $organiser && isset($query['status']) && $query['status'] !== ''
                ? TrainingStatus::tryFrom((string) $query['status'])
                : null,
            // A learner sees what is on, not what is being planned.
            openToLearnersOnly: !$organiser,
        );

        return Json::page(
            $response,
            array_map(static fn (LiveTraining $t): array => $t->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
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
        $this->caller($request)->mustHave(Permission::IltSessionView);

        $tenantId = $this->tenantId($request);
        $training = $this->requireTraining($request, $args['id'] ?? '');
        $organiser = $this->isOrganiser($request);

        if (!$organiser && !$training->status->isOpenToLearners()) {
            // A 404 rather than a 403: whether an unpublished programme exists
            // is not something a learner should be able to probe.
            throw new EntityNotFound('Training', $args['id'] ?? '');
        }

        $sessions = $this->trainings->sessionsFor($tenantId, $training->id);

        return Json::ok($response, $training->toArray([
            'sessions' => array_map(
                static fn (TrainingSession $s): array => $s->toArray(includeHostUrl: $organiser),
                $sessions,
            ),
            // What the learner's own place looks like, if they have one.
            'myEnrolment' => $organiser
                ? null
                : $this->trainings->findEnrolment($tenantId, $training->id, $this->userId($request)),
        ]));
    }

    /**
     * @param array<string, string> $args
     */
    public function save(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $id = $args['id'] ?? null;
        $existing = $id === null ? null : $this->requireTraining($request, $id);

        if ($existing !== null) {
            $this->mustBeEditable($existing);
        }

        $training = LiveTraining::fromInput(
            $existing->id ?? Uuid::uuid4()->toString(),
            $tenantId,
            $existing === null
                ? [...$this->body($request), 'status' => 'draft']
                : [
                    ...$existing->toArray(),
                    ...$this->body($request),
                    // Preserved: publishing goes through setStatus().
                    'status' => $existing->status->value,
                ],
        );

        if ($existing === null) {
            $created = $this->trainings->create(new LiveTraining(
                ...[...get_object_vars($training), 'createdBy' => $this->userId($request)],
            ));

            return Json::created($response, $created->toArray(), '/v1/trainings/' . $created->id);
        }

        if ($training->delivery !== $existing->delivery && $existing->sessionCount > 0) {
            // Switching an in-person programme online would leave its sessions
            // needing meetings nobody asked for, and the reverse would strand
            // meetings already booked.
            throw new ConflictingState(
                'Change the sessions before changing how the programme is delivered.',
                'delivery_change_blocked',
            );
        }

        return Json::ok($response, $this->trainings->update($training)->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function setStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $training = $this->requireTraining($request, $args['id'] ?? '');

        $requested = strtolower(trim((string) ($this->body($request)['status'] ?? '')));
        $status = TrainingStatus::tryFrom($requested);

        if ($status === null) {
            throw ValidationFailed::fromArray([
                'status' => 'Choose one of: ' . implode(', ', array_map(
                    static fn (TrainingStatus $s): string => $s->value,
                    TrainingStatus::cases(),
                )),
            ]);
        }

        if ($status->isOpenToLearners()) {
            $sessions = $this->trainings->sessionsFor($tenantId, $training->id);

            if ($sessions === []) {
                // A programme with no sessions is something people can enrol
                // on and then never be asked to attend.
                throw new ConflictingState(
                    'This programme has no sessions yet.',
                    'training_empty',
                );
            }
        }

        $updated = $this->trainings->setStatus($tenantId, $training->id, $status);

        return $updated === null
            ? throw new EntityNotFound('Training', $training->id)
            : Json::ok($response, $updated->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $training = $this->requireTraining($request, $args['id'] ?? '');

        // The meetings go too. A cancelled programme leaving live Zoom links
        // in people's calendars is how a room fills up for nothing.
        foreach ($this->trainings->sessionsFor($tenantId, $training->id) as $session) {
            if ($session->hasMeeting()) {
                $this->scheduler->cancel($session);
            }
        }

        return $this->trainings->softDelete($tenantId, $training->id)
            ? Json::noContent($response)
            : throw new EntityNotFound('Training', $training->id);
    }
}
