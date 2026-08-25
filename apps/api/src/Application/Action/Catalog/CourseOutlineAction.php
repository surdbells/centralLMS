<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\CourseModule;
use Central\Api\Domain\Catalog\Lesson;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/courses/{courseId}/outline
 *
 * The whole structure — modules with their lessons — in two queries.
 *
 * This is the read the course player makes on every open. The legacy screen
 * fetched the modules and then one lesson list per module, so a twelve-module
 * course cost thirteen round trips; the shape here is the same, the cost is
 * not.
 *
 * What a caller sees depends on what they may do:
 *
 * - with `course.manage`: everything, drafts and deactivated rows included,
 *   because an author cannot fix what they cannot see;
 * - otherwise: published courses only, and only active modules and lessons.
 *
 * Lesson bodies are deliberately included. Withholding `contentUrl` here
 * would be access control by omission — the enrolment check belongs in the
 * enrolments module, and pretending otherwise invites both a leak and a
 * second round trip.
 */
final class CourseOutlineAction extends StructureAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::CourseView);

        $tenantId = $this->tenantId($request);
        $courseId = $args['courseId'] ?? '';

        $course = $this->requireCourse($request, $courseId);
        $isAuthor = $caller->can(Permission::CourseManage);

        if (!$isAuthor && !$course->status->isVisibleToLearners()) {
            // A 404 rather than a 403: whether an unpublished course exists is
            // not something a learner should be able to probe.
            throw new EntityNotFound('Course', $courseId);
        }

        $activeOnly = !$isAuthor;

        $modules = $this->modules->forCourse($tenantId, $course->id, $activeOnly);
        $lessonsByModule = $this->lessons->forCourseByModule($tenantId, $course->id, $activeOnly);

        $outline = array_map(
            static fn (CourseModule $m): array => $m
                ->withLessons($lessonsByModule[$m->id] ?? [])
                ->toArray(withLessons: true),
            $modules,
        );

        return Json::ok($response, [
            'course'  => $course->toArray(),
            'modules' => $outline,
            'totals'  => [
                'modules'         => count($modules),
                'lessons'         => array_sum(array_map(
                    static fn (array $lessons): int => count($lessons),
                    $lessonsByModule,
                )),
                'durationMinutes' => $this->totalDuration($lessonsByModule),
            ],
        ]);
    }

    /**
     * Null durations are treated as zero rather than making the total null:
     * "3 hours so far" is more useful than "unknown" when one lesson has no
     * estimate recorded.
     *
     * @param array<string, list<Lesson>> $lessonsByModule
     */
    private function totalDuration(array $lessonsByModule): int
    {
        $total = 0;

        foreach ($lessonsByModule as $lessons) {
            foreach ($lessons as $lesson) {
                $total += $lesson->durationMinutes ?? 0;
            }
        }

        return $total;
    }
}
