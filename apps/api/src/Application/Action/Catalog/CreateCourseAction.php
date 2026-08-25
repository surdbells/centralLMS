<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\Course;
use Central\Api\Domain\Exception\ConflictingState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * POST /v1/courses
 *
 * Always creates a draft. Publishing is a separate act with its own
 * permission, so creating a course can never accidentally expose it.
 */
final class CreateCourseAction extends CourseAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CourseManage);

        $tenantId = $this->tenantId($request);

        $course = Course::fromInput(
            Uuid::uuid4()->toString(),
            $tenantId,
            // Whatever status was sent is ignored; see the class docblock.
            [...$this->body($request), 'status' => 'draft'],
        );

        if ($this->courses->existsWithTitle($tenantId, $course->title)) {
            throw new ConflictingState(
                sprintf('A course titled "%s" already exists.', $course->title),
                'duplicate_title',
            );
        }

        $created = $this->courses->create($course);

        return Json::created($response, $created->toArray(), '/v1/courses/' . $created->id);
    }
}
