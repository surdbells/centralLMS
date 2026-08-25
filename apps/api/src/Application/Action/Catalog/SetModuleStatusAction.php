<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /v1/courses/{courseId}/modules/{id}/status
 *
 * Activates or deactivates. Deactivating hides the module from learners
 * without destroying it or the progress recorded against its lessons.
 */
final class SetModuleStatusAction extends StructureAction
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

        $body = $this->body($request);

        if (!array_key_exists('isActive', $body)) {
            throw ValidationFailed::fromArray(['isActive' => 'Send true or false.']);
        }

        $isActive = filter_var($body['isActive'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($isActive === null) {
            throw ValidationFailed::fromArray(['isActive' => 'Send true or false.']);
        }

        $updated = $this->modules->setActive($this->tenantId($request), $courseId, $module->id, $isActive);

        return $updated === null
            ? throw new EntityNotFound('Module', $module->id)
            : Json::ok($response, $updated->toArray());
    }
}
