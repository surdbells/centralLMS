<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\CourseModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v1/courses/{courseId}/modules/order
 *
 * Body: `{"order": ["<id>", "<id>", …]}` — every module in the course, in the
 * order they should appear.
 *
 * A dedicated endpoint because the alternative is one PUT per module: N round
 * trips, and any failure halfway through leaves two modules sharing a
 * position. Here the whole order lands in a single statement or not at all.
 */
final class ReorderModulesAction extends StructureAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CourseManage);

        $tenantId = $this->tenantId($request);
        $course = $this->requireCourse($request, $args['courseId'] ?? '');
        $this->mustBeEditable($course);

        $existing = array_map(
            static fn (CourseModule $m): string => $m->id,
            $this->modules->forCourse($tenantId, $course->id),
        );

        $order = $this->requestedOrder($request, $existing);

        $this->modules->reorder($tenantId, $course->id, $order);

        return Json::ok($response, array_map(
            static fn (CourseModule $m): array => $m->toArray(),
            $this->modules->forCourse($tenantId, $course->id),
        ));
    }
}
