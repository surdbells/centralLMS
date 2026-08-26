<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/courses/statistics
 *
 * Headline counts for the course screen. Every one is counted at read time,
 * including the enrolment total — the legacy kept `total_enrollments` on the
 * course row and never decremented it, so its one live course claimed
 * nineteen learners against thirty-three enrolment rows.
 */
final class CourseStatisticsAction extends CourseAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CourseManage);

        return Json::ok($response, $this->courses->statistics($this->tenantId($request)));
    }
}
