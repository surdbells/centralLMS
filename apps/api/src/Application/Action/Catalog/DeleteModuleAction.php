<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v1/courses/{courseId}/modules/{id}
 *
 * Soft-deletes the module and its lessons together. Progress rows keep
 * pointing at real lessons, so a certificate issued last year still resolves.
 */
final class DeleteModuleAction extends StructureAction
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

        $courseId = $args['courseId'] ?? '';
        $this->mustBeEditable($this->requireCourse($request, $courseId));

        $module = $this->requireModule($request, $courseId, $args['id'] ?? '');

        return $this->modules->softDelete($this->tenantId($request), $courseId, $module->id)
            ? Json::noContent($response)
            : throw new EntityNotFound('Module', $module->id);
    }
}
