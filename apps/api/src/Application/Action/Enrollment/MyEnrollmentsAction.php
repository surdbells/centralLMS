<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Enrollment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/me/enrollments
 *
 * The learner's own courses. Overdue work first, then whatever they touched
 * most recently — the home screen should open on what is late.
 *
 * Scoped to the caller by construction: there is no user id in the path, so
 * there is nothing to tamper with.
 */
final class MyEnrollmentsAction extends EnrollmentAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::EnrollmentSelf);

        $listing = $this->listing($request);

        $result = $this->enrollments->forUser(
            $this->tenantId($request),
            $this->userId($request),
            $listing['page'],
            $listing['perPage'],
            $listing['status'],
        );

        return Json::page(
            $response,
            $this->serialise($result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }
}
