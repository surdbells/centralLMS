<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\Lesson;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v1/courses/{courseId}/modules/{moduleId}/lessons/order
 *
 * Body: `{"order": ["<id>", "<id>", …]}` — every lesson in the module.
 * See ReorderModulesAction for why this is one endpoint and not N updates.
 */
final class ReorderLessonsAction extends StructureAction
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
        $courseId = $args['courseId'] ?? '';
        $this->mustBeEditable($this->requireCourse($request, $courseId));

        $module = $this->requireModule($request, $courseId, $args['moduleId'] ?? '');

        $existing = array_map(
            static fn (Lesson $l): string => $l->id,
            $this->lessons->forModule($tenantId, $module->id),
        );

        $order = $this->requestedOrder($request, $existing);

        $this->lessons->reorder($tenantId, $module->id, $order);

        return Json::ok($response, array_map(
            static fn (Lesson $l): array => $l->toArray(),
            $this->lessons->forModule($tenantId, $module->id),
        ));
    }
}
