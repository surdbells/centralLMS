<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Ilt;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/me/trainings
 *
 * The instructor-led programmes the caller is booked on, with the sessions of
 * each and how their attendance stands.
 *
 * There was no way to ask this. `/trainings/{id}/roster` is the other
 * direction — other people's names, emails and attendance — and needs the
 * organiser permission for that reason; a learner has neither the permission
 * nor the list of programmes to ask about one at a time. The learner's Live
 * Training screen said "No upcoming sessions" to everybody because of it.
 *
 * Who is asking comes from the token. The legacy took a `learner_id`
 * alongside the credentials, which is how one person read another's record.
 */
final class MyTrainingsAction extends TrainingAction
{
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentSelf);

        return Json::ok($response, $this->trainings->enrolmentsForUser(
            $this->tenantId($request),
            $this->userId($request),
        ));
    }
}
