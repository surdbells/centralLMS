<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\Course;
use Central\Api\Domain\Catalog\CourseModule;
use Central\Api\Domain\Catalog\CourseRepository;
use Central\Api\Domain\Catalog\CourseStatus;
use Central\Api\Domain\Catalog\Lesson;
use Central\Api\Domain\Catalog\LessonRepository;
use Central\Api\Domain\Catalog\ModuleRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * POST /v1/courses/{id}/duplicate
 *
 * A copy of the course, its modules and its lessons, as a draft.
 *
 * **What is deliberately not copied**, because copying it would be a lie:
 *
 *  - enrolments, progress, attempts and certificates — they happened to
 *    people on the original course, not on this one
 *  - the rating — nobody has rated a course that did not exist a moment ago
 *  - `publishedAt`, and the status, which starts as a draft. A copy that
 *    arrived published would put an unreviewed course in the catalogue.
 *  - the skills it teaches. A copy is usually the start of a variant, and
 *    inheriting the mapping would silently grant the same competencies for
 *    different content.
 *
 * The title gets a suffix rather than being asked for, and the suffix counts
 * up, so duplicating twice does not collide with the unique title index.
 */
final class DuplicateCourseAction extends CourseAction
{
    public function __construct(
        CourseRepository $courses,
        private readonly ModuleRepository $modules,
        private readonly LessonRepository $lessons,
    ) {
        parent::__construct($courses);
    }

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
        $original = $this->requireCourse($request, $args['id'] ?? '');

        $copy = $this->courses->create(new Course(
            id: Uuid::uuid4()->toString(),
            tenantId: $tenantId,
            title: $this->freeTitle($tenantId, $original->title),
            // Always a draft, whatever the original was.
            status: CourseStatus::Draft,
            summary: $original->summary,
            description: $original->description,
            objectives: $original->objectives,
            kind: $original->kind,
            level: $original->level,
            categoryId: $original->categoryId,
            instructorId: $original->instructorId,
            thumbnailUrl: $original->thumbnailUrl,
            passPercentage: $original->passPercentage,
            durationMinutes: $original->durationMinutes,
            enrollmentLimit: $original->enrollmentLimit,
            isMandatory: $original->isMandatory,
            // A copy is not featured. Featuring is an editorial decision about
            // one course, and it would otherwise be made twice by accident.
            isFeatured: false,
            isOnboarding: $original->isOnboarding,
            allowSelfEnrollment: $original->allowSelfEnrollment,
            startsOn: $original->startsOn,
            endsOn: $original->endsOn,
        ));

        $modules = 0;
        $lessons = 0;

        foreach ($this->modules->forCourse($tenantId, $original->id) as $module) {
            $newModule = $this->modules->create(new CourseModule(
                id: Uuid::uuid4()->toString(),
                tenantId: $tenantId,
                courseId: $copy->id,
                title: $module->title,
                description: $module->description,
                sortOrder: $module->sortOrder,
                isActive: $module->isActive,
            ));

            ++$modules;

            foreach ($this->lessons->forModule($tenantId, $module->id) as $lesson) {
                $this->lessons->create(new Lesson(
                    id: Uuid::uuid4()->toString(),
                    tenantId: $tenantId,
                    courseId: $copy->id,
                    moduleId: $newModule->id,
                    title: $lesson->title,
                    contentType: $lesson->contentType,
                    description: $lesson->description,
                    // The same media, not a copy of it. A file in the library
                    // is one file; duplicating a course should not duplicate
                    // four gigabytes of video.
                    contentUrl: $lesson->contentUrl,
                    contentText: $lesson->contentText,
                    durationMinutes: $lesson->durationMinutes,
                    sortOrder: $lesson->sortOrder,
                    isMandatory: $lesson->isMandatory,
                    isPreview: $lesson->isPreview,
                    isActive: $lesson->isActive,
                ));

                ++$lessons;
            }
        }

        return Json::created(
            $response,
            [...$copy->toArray(), 'copiedModules' => $modules, 'copiedLessons' => $lessons],
            '/v1/courses/' . $copy->id,
        );
    }

    /**
     * "Fire Safety" becomes "Fire Safety (copy)", then "(copy 2)".
     *
     * Titles are unique per tenant, so a second duplicate of the same course
     * would otherwise be refused for a reason that is not the caller's fault.
     */
    private function freeTitle(string $tenantId, string $title): string
    {
        $base      = preg_replace('/ \(copy(?: \d+)?\)$/', '', $title) ?? $title;
        $candidate = $base . ' (copy)';

        for ($n = 2; $this->courses->existsWithTitle($tenantId, $candidate); ++$n) {
            $candidate = sprintf('%s (copy %d)', $base, $n);

            // The unique index is the real guard; this loop only picks a name.
            // A tenant with a hundred copies of one course has other problems.
            if ($n > 100) {
                return sprintf('%s (copy %s)', $base, substr(Uuid::uuid4()->toString(), 0, 8));
            }
        }

        return $candidate;
    }
}
