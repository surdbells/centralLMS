<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Ilt;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Enrollment\EnrollmentSource;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is on a programme.
 *
 * GET    /v1/trainings/{id}/roster
 * POST   /v1/trainings/{id}/roster      assign people
 * DELETE /v1/trainings/{id}/roster      withdraw them
 * POST   /v1/trainings/{id}/enrol       a learner taking a place themselves
 */
final class TrainingEnrolmentAction extends TrainingAction
{
    /**
     * @param array<string, string> $args
     */
    public function roster(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        // The register is other people's names, emails and attendance, so it
        // needs the organiser permission rather than the viewing one.
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $training = $this->requireTraining($request, $args['id'] ?? '');
        $listing = $this->listing($request);

        $result = $this->trainings->rosterFor(
            $tenantId,
            $training->id,
            $listing['page'],
            $listing['perPage'],
        );

        return Json::page($response, $result['items'], $result['total'], $listing['page'], $listing['perPage']);
    }

    /**
     * @param array<string, string> $args
     */
    public function assign(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $training = $this->requireTraining($request, $args['id'] ?? '');

        if (!$training->status->isOpenToLearners()) {
            throw new ConflictingState(
                'Publish the programme before assigning it to anyone.',
                'training_not_published',
            );
        }

        $userIds = $this->userIds($this->body($request)['userIds'] ?? null);

        $outcome = $this->trainings->enrol(
            $tenantId,
            $training,
            $userIds,
            count($userIds) === 1 ? EnrollmentSource::Direct : EnrollmentSource::Bulk,
            $this->userId($request),
        );

        return Json::ok($response, [
            'trainingId' => $training->id,
            'enrolled'   => count($outcome['enrolled']),
            // Distinct from enrolled: they have a place in the queue, not the
            // room, and telling an organiser otherwise is how a session is
            // over-booked.
            'waitlisted'      => count($outcome['waitlisted']),
            'alreadyEnrolled' => count($outcome['alreadyEnrolled']),
            'notFound'        => $outcome['notFound'],
            // Full, and no waitlist to fall back on.
            'rejected'        => count($outcome['rejected']),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function withdraw(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $training = $this->requireTraining($request, $args['id'] ?? '');
        $userIds = $this->userIds($this->body($request)['userIds'] ?? null);

        $removed = $this->trainings->withdraw($tenantId, $training->id, $userIds);

        return Json::ok($response, [
            'trainingId'  => $training->id,
            'removed'     => count($removed),
            'notEnrolled' => array_values(array_diff($userIds, $removed)),
        ]);
    }

    /**
     * A learner taking a place themselves.
     *
     * @param array<string, string> $args
     */
    public function selfEnrol(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentSelf);

        $tenantId = $this->tenantId($request);
        $userId = $this->userId($request);
        $trainingId = $args['id'] ?? '';
        $training = $this->requireTraining($request, $trainingId);

        if (!$training->status->isOpenToLearners()) {
            throw new EntityNotFound('Training', $trainingId);
        }

        $existing = $this->trainings->findEnrolment($tenantId, $training->id, $userId);

        if ($existing !== null && $existing['status'] !== 'dropped') {
            // Idempotent: someone double-clicking Enrol should land on the
            // programme, not on an error.
            return Json::ok($response, $existing);
        }

        $outcome = $this->trainings->enrol(
            $tenantId,
            $training,
            [$userId],
            EnrollmentSource::SelfEnrolled,
            null,
        );

        if ($outcome['rejected'] !== []) {
            throw new ConflictingState('This programme is full.', 'training_full');
        }

        $enrolment = $this->trainings->findEnrolment($tenantId, $training->id, $userId)
            ?? throw new \RuntimeException('Enrolment could not be read back.');

        return Json::created($response, [
            ...$enrolment,
            // Said plainly, because a place on the waitlist is not a place.
            'message' => $enrolment['waitlisted']
                ? sprintf('This programme is full. You are number %d on the waitlist.', $enrolment['waitlistPosition'])
                : 'You are enrolled.',
        ], '/v1/trainings/' . $training->id);
    }

    /**
     * @return list<string>
     *
     * @throws ValidationFailed
     */
    private function userIds(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw ValidationFailed::fromArray(['userIds' => 'Name who.']);
        }

        $ids = [];

        foreach ($raw as $id) {
            $id = is_scalar($id) ? trim((string) $id) : '';

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            throw ValidationFailed::fromArray(['userIds' => 'Name who.']);
        }

        return $ids;
    }
}
