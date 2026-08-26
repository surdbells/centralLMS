<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\LearningPath;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Catalog\CourseStatus;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\LearningPath\CompletionRule;
use Central\Api\Domain\LearningPath\LearningPath;
use Central\Api\Domain\LearningPath\LearningPathRepository;
use Central\Api\Domain\LearningPath\PathEnrollment;
use Central\Api\Domain\LearningPath\PathFilters;
use Central\Api\Domain\LearningPath\PathItem;
use Central\Api\Domain\LearningPath\PathItemKind;
use Central\Api\Domain\LearningPath\PathPacing;
use Central\Api\Domain\LearningPath\PathProgress;
use Central\Api\Domain\LearningPath\PathSection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Learning paths: authoring, enrolment and progress.
 *
 * GET    /v1/paths
 * POST   /v1/paths
 * GET    /v1/paths/statistics
 * GET    /v1/paths/catalogue
 * GET    /v1/paths/{id}
 * PUT    /v1/paths/{id}
 * PATCH  /v1/paths/{id}/status
 * DELETE /v1/paths/{id}
 * GET    /v1/paths/{id}/sections
 * POST   /v1/paths/{id}/sections
 * PUT    /v1/paths/{id}/sections/{sectionId}
 * DELETE /v1/paths/{id}/sections/{sectionId}
 * GET    /v1/paths/{id}/items
 * POST   /v1/paths/{id}/items
 * PUT    /v1/paths/{id}/items/{itemId}
 * DELETE /v1/paths/{id}/items/{itemId}
 * PUT    /v1/paths/{id}/items/order
 * GET    /v1/paths/{id}/enrollments
 * POST   /v1/paths/{id}/enrollments
 * DELETE /v1/paths/{id}/enrollments/{userId}
 * GET    /v1/me/paths
 * GET    /v1/me/paths/{id}
 * POST   /v1/me/paths/{id}/enroll
 * POST   /v1/me/paths/{id}/items/{itemId}/complete
 *
 * **Nothing here writes progress.** A learner's position on a path is worked
 * out from the records that already exist — the course enrolment, the
 * assessment attempt, the attendance register — by {@see PathProgress}. The
 * only progress written is a mark against a link or a document, which produce
 * no evidence of their own.
 */
final class ManagePathsAction
{
    public function __construct(private readonly LearningPathRepository $paths)
    {
    }

    // --- authoring --------------------------------------------------------

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->authorable($request);

        $violations = [];
        $filters    = PathFilters::fromQuery($request->getQueryParams(), $violations);

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return $this->page($request, $response, $tenantId, $filters);
    }

    /**
     * What a learner browsing may see.
     */
    public function catalogue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::CourseView);

        return $this->page($request, $response, $this->tenantOf($caller), PathFilters::catalogue());
    }

    public function statistics(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::ok($response, $this->paths->statistics($this->authorable($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->authorable($request);
        $path     = $this->requirePath($tenantId, $args['id']);

        return Json::ok($response, [
            ...$path->toArray(),
            'sections' => array_map(
                static fn (PathSection $s): array => $s->toArray(),
                $this->paths->sections($tenantId, $args['id']),
            ),
            'items' => array_map(
                static fn (PathItem $i): array => $i->toArray(),
                $this->paths->items($tenantId, $args['id']),
            ),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller   = $this->caller($request);
        $caller->mustHave(Permission::CourseManage);
        $tenantId = $this->tenantOf($caller);

        $path = $this->paths->save($this->pathFrom($request, $tenantId, null, $caller->id));

        return Json::created($response, $path->toArray(), '/v1/paths/' . $path->id);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->manageable($request);
        $existing = $this->requirePath($tenantId, $args['id']);

        return Json::ok($response, $this->paths->save(
            $this->pathFrom($request, $tenantId, $existing, $existing->createdBy),
        )->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function setStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::CoursePublish);
        $tenantId = $this->tenantOf($caller);

        $body   = (array) ($request->getParsedBody() ?? []);
        $status = CourseStatus::tryFrom(strtolower(trim((string) ($body['status'] ?? ''))));

        if ($status === null) {
            throw ValidationFailed::fromArray(['status' => 'Choose draft, published or archived.']);
        }

        $path = $this->requirePath($tenantId, $args['id']);

        if ($status === CourseStatus::Published && $path->itemCount === 0) {
            // The path in the legacy database is published with every one of
            // its items deactivated, and thirty-three people are enrolled on
            // it. Publishing an empty path is how that happens.
            throw new ConflictingState(
                'A path needs something in it before it can be published.',
                'path_is_empty',
            );
        }

        $updated = $this->paths->setStatus($tenantId, $args['id'], $status->value)
            ?? throw new EntityNotFound('Learning path', $args['id']);

        return Json::ok($response, $updated->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->manageable($request);

        if (!$this->paths->softDelete($tenantId, $args['id'])) {
            throw new EntityNotFound('Learning path', $args['id']);
        }

        return Json::noContent($response);
    }

    // --- contents ---------------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function sections(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->authorable($request);
        $this->requirePath($tenantId, $args['id']);

        return Json::ok($response, array_map(
            static fn (PathSection $s): array => $s->toArray(),
            $this->paths->sections($tenantId, $args['id']),
        ));
    }

    /**
     * @param array<string, string> $args
     */
    public function saveSection(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->manageable($request);
        $this->requirePath($tenantId, $args['id']);

        $body  = (array) ($request->getParsedBody() ?? []);
        $title = trim((string) ($body['title'] ?? ''));

        if ($title === '') {
            throw ValidationFailed::fromArray(['title' => 'Give the section a name.']);
        }

        $minimum = isset($body['minItemsToComplete'])
            ? max(1, (int) $body['minItemsToComplete'])
            : null;

        $section = $this->paths->saveSection(new PathSection(
            id: $args['sectionId'] ?? null,
            tenantId: $tenantId,
            pathId: $args['id'],
            title: $title,
            description: $this->textOrNull($body['description'] ?? null),
            sortOrder: (int) ($body['sortOrder'] ?? 0),
            isMilestone: filter_var($body['isMilestone'] ?? false, FILTER_VALIDATE_BOOL),
            minItemsToComplete: $minimum,
        ));

        return Json::ok($response, $section->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteSection(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->manageable($request);

        if (!$this->paths->deleteSection($tenantId, $args['sectionId'])) {
            throw new EntityNotFound('Section', $args['sectionId']);
        }

        return Json::noContent($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function items(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->authorable($request);
        $this->requirePath($tenantId, $args['id']);

        return Json::ok($response, array_map(
            static fn (PathItem $i): array => $i->toArray(),
            $this->paths->items($tenantId, $args['id']),
        ));
    }

    /**
     * @param array<string, string> $args
     */
    public function saveItem(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->manageable($request);
        $this->requirePath($tenantId, $args['id']);

        $existing = isset($args['itemId'])
            ? ($this->paths->findItem($tenantId, $args['itemId']) ?? throw new EntityNotFound('Item', $args['itemId']))
            : null;

        $item = $this->paths->saveItem($this->itemFrom($request, $tenantId, $args['id'], $existing));

        return $existing === null
            ? Json::created($response, $item->toArray())
            : Json::ok($response, $item->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteItem(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->manageable($request);

        if (!$this->paths->deleteItem($tenantId, $args['itemId'])) {
            throw new EntityNotFound('Item', $args['itemId']);
        }

        return Json::noContent($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function reorder(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->manageable($request);
        $this->requirePath($tenantId, $args['id']);

        $body  = (array) ($request->getParsedBody() ?? []);
        $given = $body['itemIds'] ?? [];

        if (!is_array($given) || $given === []) {
            throw ValidationFailed::fromArray(['itemIds' => 'Send the items in their new order.']);
        }

        $ids = [];

        foreach ($given as $id) {
            $id = trim((string) $id);

            if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
                throw ValidationFailed::fromArray(['itemIds' => 'One of those is not an item id.']);
            }

            $ids[] = $id;
        }

        $this->paths->reorderItems($tenantId, $args['id'], $ids);

        return Json::ok($response, array_map(
            static fn (PathItem $i): array => $i->toArray(),
            $this->paths->items($tenantId, $args['id']),
        ));
    }

    // --- enrolment --------------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function enrolments(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentManage);
        $tenantId = $this->tenantOf($caller);

        $this->requirePath($tenantId, $args['id']);

        $listing = $this->listing($request);
        $result  = $this->paths->enrolments($tenantId, $args['id'], $listing['page'], $listing['perPage']);

        return Json::page(
            $response,
            array_map(
                fn (PathEnrollment $e): array => [
                    ...$e->toArray(),
                    // Each learner's position, derived. The legacy stored six
                    // figures per row and every one of them was wrong.
                    'progress' => $this->progressFor($tenantId, $args['id'], $e->userId)->toArray(),
                ],
                $result['items'],
            ),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function enrol(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentManage);
        $tenantId = $this->tenantOf($caller);

        $path  = $this->requirePath($tenantId, $args['id']);
        $body  = (array) ($request->getParsedBody() ?? []);
        $given = $body['userIds'] ?? [];

        if (!is_array($given) || $given === []) {
            throw ValidationFailed::fromArray(['userIds' => 'Say who is being enrolled.']);
        }

        $result = $this->paths->enrol(
            $tenantId,
            $args['id'],
            array_values(array_map(strval(...), $given)),
            'direct',
            $caller->id,
            $this->dateOrNull($body['dueDate'] ?? null),
            filter_var($body['isMandatory'] ?? $path->isMandatory, FILTER_VALIDATE_BOOL),
        );

        return Json::ok($response, $result);
    }

    /**
     * @param array<string, string> $args
     */
    public function withdraw(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentManage);
        $tenantId = $this->tenantOf($caller);

        if (!$this->paths->withdraw($tenantId, $args['id'], $args['userId'])) {
            throw new EntityNotFound('Enrolment', $args['userId']);
        }

        return Json::noContent($response);
    }

    // --- the learner's own view -------------------------------------------

    public function mine(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller   = $this->caller($request);
        $tenantId = $this->tenantOf($caller);

        $status = $this->textOrNull($request->getQueryParams()['status'] ?? null);

        return Json::ok($response, array_map(
            fn (PathEnrollment $e): array => [
                ...$e->toArray(),
                'progress' => $this->progressFor($tenantId, $e->pathId, $caller->id)->toArray(),
            ],
            $this->paths->forLearner($tenantId, $caller->id, $status),
        ));
    }

    /**
     * One path, as it stands for the caller.
     *
     * @param array<string, string> $args
     */
    public function mineShow(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller   = $this->caller($request);
        $tenantId = $this->tenantOf($caller);

        $path       = $this->requirePath($tenantId, $args['id']);
        $enrollment = $this->paths->enrolmentFor($tenantId, $args['id'], $caller->id);

        if ($enrollment === null && !$path->isOpenToLearners()) {
            // Not enrolled and not published: as far as this learner is
            // concerned it does not exist.
            throw new EntityNotFound('Learning path', $args['id']);
        }

        $progress = $this->progressFor($tenantId, $args['id'], $caller->id);

        // A path finishes itself. The stored flag is a record of the event,
        // written when the derived answer says so, which is why the two can
        // never disagree.
        if ($enrollment !== null && $enrollment->isActive() && $progress->isComplete) {
            $enrollment = $this->paths->markComplete($tenantId, (string) $enrollment->id, null) ?? $enrollment;
        }

        return Json::ok($response, [
            ...$path->toArray(),
            'enrollment' => $enrollment?->toArray(),
            'sections'   => array_map(
                static fn (PathSection $s): array => $s->toArray(),
                $this->paths->sections($tenantId, $args['id']),
            ),
            'progress' => $progress->toArray(),
        ]);
    }

    /**
     * A learner putting themselves on a path.
     *
     * @param array<string, string> $args
     */
    public function selfEnrol(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentSelf);
        $tenantId = $this->tenantOf($caller);

        $path     = $this->requirePath($tenantId, $args['id']);
        $existing = $this->paths->enrolmentFor($tenantId, $args['id'], $caller->id);

        if ($existing !== null) {
            throw new ConflictingState('You are already on this path.', 'already_enrolled');
        }

        if (!$path->allowsSelfEnrolment()) {
            throw new ConflictingState(
                'This path is not open to join.',
                'self_enrolment_closed',
            );
        }

        if ($path->isFull()) {
            // `max_enrollments` was declared in the legacy and never read.
            throw new ConflictingState('This path is full.', 'path_full');
        }

        $this->paths->enrol($tenantId, $args['id'], [$caller->id], 'self', null, null, $path->isMandatory);

        $enrollment = $this->paths->enrolmentFor($tenantId, $args['id'], $caller->id)
            ?? throw new ConflictingState('You could not be enrolled.', 'enrolment_failed');

        return Json::created($response, $enrollment->toArray());
    }

    /**
     * Marking a link or a document as read.
     *
     * @param array<string, string> $args
     */
    public function completeItem(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller   = $this->caller($request);
        $tenantId = $this->tenantOf($caller);

        $enrollment = $this->paths->enrolmentFor($tenantId, $args['id'], $caller->id)
            ?? throw new EntityNotFound('Enrolment', $args['id']);

        $item = $this->paths->findItem($tenantId, $args['itemId']);

        if ($item === null || $item->pathId !== $args['id']) {
            throw new EntityNotFound('Item', $args['itemId']);
        }

        if ($item->kind->isEvidenced()) {
            // Marking a course done here would be a second opinion about the
            // course, which is the disagreement this module exists to remove.
            throw new ConflictingState(
                sprintf('Finish the %s itself; this cannot be marked by hand.', strtolower($item->kind->label())),
                'item_is_evidenced',
            );
        }

        $this->paths->markItem($tenantId, (string) $enrollment->id, (string) $item->id, 'viewed', $caller->id, null);

        return Json::ok($response, $this->progressFor($tenantId, $args['id'], $caller->id)->toArray());
    }

    // --- plumbing ---------------------------------------------------------

    private function progressFor(string $tenantId, string $pathId, string $userId): PathProgress
    {
        $path = $this->paths->find($tenantId, $pathId);

        if ($path === null) {
            throw new EntityNotFound('Learning path', $pathId);
        }

        $sections = [];

        foreach ($this->paths->sections($tenantId, $pathId) as $section) {
            $sections[(string) $section->id] = $section->minItemsToComplete;
        }

        return PathProgress::of(
            $this->paths->items($tenantId, $pathId),
            $this->paths->completionsFor($tenantId, $pathId, $userId),
            $path->pacing,
            $path->completionRule,
            $path->completionThreshold,
            $sections,
        );
    }

    private function page(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $tenantId,
        PathFilters $filters,
    ): ResponseInterface {
        $listing = $this->listing($request);
        $result  = $this->paths->paginate($tenantId, $filters, $listing['page'], $listing['perPage']);

        return Json::page(
            $response,
            array_map(static fn (LearningPath $p): array => $p->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    private function pathFrom(
        ServerRequestInterface $request,
        string $tenantId,
        ?LearningPath $existing,
        ?string $createdBy,
    ): LearningPath {
        $body  = (array) ($request->getParsedBody() ?? []);
        $title = trim((string) ($body['title'] ?? $existing->title ?? ''));

        $violations = [];

        if ($title === '') {
            $violations['title'] = 'Give the path a title.';
        }

        $slug = trim((string) ($body['slug'] ?? $existing->slug ?? ''));
        $slug = $slug === '' ? $this->slugify($title) : $this->slugify($slug);

        if ($slug !== '' && $this->paths->slugExists($tenantId, $slug, $existing?->id)) {
            // A slug is an address. Two paths sharing one means a URL that
            // resolves to whichever the database returns first.
            $violations['slug'] = 'A path with that address already exists.';
        }

        $pacing = PathPacing::tryFrom(strtolower(trim(
            (string) ($body['pacing'] ?? $existing->pacing->value ?? 'sequential'),
        )));

        if ($pacing === null) {
            $violations['pacing'] = 'Choose one of: ' . implode(', ', PathPacing::values()) . '.';
        }

        $rule = CompletionRule::tryFrom(strtolower(trim(
            (string) ($body['completionRule'] ?? $existing->completionRule->value ?? 'all_required_items'),
        )));

        if ($rule === null) {
            $violations['completionRule'] = 'Choose one of: ' . implode(', ', CompletionRule::values()) . '.';
        }

        $threshold = (float) ($body['completionThreshold'] ?? $existing->completionThreshold ?? 100);

        if ($threshold <= 0 || $threshold > 100) {
            $violations['completionThreshold'] = 'Give a percentage between 1 and 100.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new LearningPath(
            id: $existing?->id,
            tenantId: $tenantId,
            title: mb_substr($title, 0, 255),
            slug: $slug,
            status: $existing->status ?? CourseStatus::Draft,
            pacing: $pacing ?? PathPacing::Sequential,
            completionRule: $rule ?? CompletionRule::AllRequiredItems,
            completionThreshold: $threshold,
            level: (string) ($body['level'] ?? $existing->level ?? 'all_levels'),
            visibility: (string) ($body['visibility'] ?? $existing->visibility ?? 'private'),
            enrolmentType: (string) ($body['enrolmentType'] ?? $existing->enrolmentType ?? 'open'),
            isMandatory: filter_var(
                $body['isMandatory'] ?? $existing->isMandatory ?? false,
                FILTER_VALIDATE_BOOL,
            ),
            summary: $this->textOrNull($body['summary'] ?? $existing?->summary),
            description: $this->textOrNull($body['description'] ?? $existing?->description),
            thumbnailUrl: $this->textOrNull($body['thumbnailUrl'] ?? $existing?->thumbnailUrl),
            categoryId: array_key_exists('categoryId', $body)
                ? $this->uuidOrNull($body['categoryId'])
                : $existing?->categoryId,
            certificateTemplateId: array_key_exists('certificateTemplateId', $body)
                ? $this->uuidOrNull($body['certificateTemplateId'])
                : $existing?->certificateTemplateId,
            certificateValidityMonths: isset($body['certificateValidityMonths'])
                ? max(1, (int) $body['certificateValidityMonths'])
                : $existing?->certificateValidityMonths,
            estimatedHours: isset($body['estimatedHours'])
                ? (float) $body['estimatedHours']
                : $existing?->estimatedHours,
            maxEnrolments: isset($body['maxEnrolments'])
                ? max(1, (int) $body['maxEnrolments'])
                : $existing?->maxEnrolments,
            tags: $this->stringList($body['tags'] ?? $existing->tags ?? []),
            objectives: $this->stringList($body['objectives'] ?? $existing->objectives ?? []),
            createdBy: $createdBy,
        );
    }

    private function itemFrom(
        ServerRequestInterface $request,
        string $tenantId,
        string $pathId,
        ?PathItem $existing,
    ): PathItem {
        $body = (array) ($request->getParsedBody() ?? []);

        // The kind and what it points at are fixed at creation. Changing a
        // course item into an assessment item is deleting one and adding
        // another, and pretending otherwise means every completion recorded
        // against it silently changes meaning.
        if ($existing !== null) {
            return new PathItem(
                id: $existing->id,
                tenantId: $tenantId,
                pathId: $pathId,
                kind: $existing->kind,
                sectionId: array_key_exists('sectionId', $body)
                    ? $this->uuidOrNull($body['sectionId'])
                    : $existing->sectionId,
                courseId: $existing->courseId,
                assessmentId: $existing->assessmentId,
                sessionId: $existing->sessionId,
                mediaId: $existing->mediaId,
                externalUrl: $existing->externalUrl,
                title: $this->textOrNull($body['title'] ?? $existing->title),
                description: $this->textOrNull($body['description'] ?? $existing->description),
                sortOrder: (int) ($body['sortOrder'] ?? $existing->sortOrder),
                isRequired: filter_var($body['isRequired'] ?? $existing->isRequired, FILTER_VALIDATE_BOOL),
                pointsValue: max(0, (int) ($body['pointsValue'] ?? $existing->pointsValue)),
                dueDate: array_key_exists('dueDate', $body)
                    ? $this->dateOrNull($body['dueDate'])
                    : $existing->dueDate,
                dueDaysFromEnrolment: array_key_exists('dueDaysFromEnrolment', $body)
                    ? ($body['dueDaysFromEnrolment'] === null ? null : max(1, (int) $body['dueDaysFromEnrolment']))
                    : $existing->dueDaysFromEnrolment,
                estimatedMinutes: isset($body['estimatedMinutes'])
                    ? max(0, (int) $body['estimatedMinutes'])
                    : $existing->estimatedMinutes,
            );
        }

        $kind = PathItemKind::tryFrom(strtolower(trim((string) ($body['kind'] ?? ''))));

        if ($kind === null) {
            throw ValidationFailed::fromArray([
                'kind' => 'Choose one of: ' . implode(', ', PathItemKind::values()) . '.',
            ]);
        }

        $subjectId = $this->uuidOrNull($body['subjectId'] ?? null);
        $url       = $this->textOrNull($body['externalUrl'] ?? null);
        $title     = $this->textOrNull($body['title'] ?? null);

        if ($kind === PathItemKind::Link) {
            if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw ValidationFailed::fromArray(['externalUrl' => 'Give a web address.']);
            }

            if ($title === null) {
                throw ValidationFailed::fromArray(['title' => 'Give the link a name.']);
            }
        } elseif ($subjectId === null) {
            throw ValidationFailed::fromArray([
                'subjectId' => sprintf('Say which %s this step is.', strtolower($kind->label())),
            ]);
        }

        return new PathItem(
            id: null,
            tenantId: $tenantId,
            pathId: $pathId,
            kind: $kind,
            sectionId: $this->uuidOrNull($body['sectionId'] ?? null),
            courseId: $kind === PathItemKind::Course ? $subjectId : null,
            assessmentId: $kind === PathItemKind::Assessment ? $subjectId : null,
            sessionId: $kind === PathItemKind::Session ? $subjectId : null,
            mediaId: $kind === PathItemKind::Media ? $subjectId : null,
            externalUrl: $kind === PathItemKind::Link ? $url : null,
            title: $title,
            description: $this->textOrNull($body['description'] ?? null),
            sortOrder: (int) ($body['sortOrder'] ?? 0),
            isRequired: filter_var($body['isRequired'] ?? true, FILTER_VALIDATE_BOOL),
            pointsValue: max(0, (int) ($body['pointsValue'] ?? 0)),
            dueDate: $this->dateOrNull($body['dueDate'] ?? null),
            dueDaysFromEnrolment: isset($body['dueDaysFromEnrolment'])
                ? max(1, (int) $body['dueDaysFromEnrolment'])
                : null,
            estimatedMinutes: isset($body['estimatedMinutes']) ? max(0, (int) $body['estimatedMinutes']) : null,
        );
    }

    private function requirePath(string $tenantId, string $id): LearningPath
    {
        return $this->paths->find($tenantId, $id) ?? throw new EntityNotFound('Learning path', $id);
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantOf(AuthenticatedUser $caller): string
    {
        return $caller->tenantId ?? throw new AccessDenied(Permission::CourseView->value);
    }

    /** Seeing the authoring list, including drafts. */
    private function authorable(ServerRequestInterface $request): string
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::CourseManage);

        return $this->tenantOf($caller);
    }

    private function manageable(ServerRequestInterface $request): string
    {
        return $this->authorable($request);
    }

    /**
     * @return array{page: int, perPage: int}
     */
    private function listing(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            'page'    => max(1, (int) ($query['page'] ?? 1)),
            'perPage' => max(1, min(100, (int) ($query['perPage'] ?? 25))),
        ];
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * @param  mixed        $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => trim((string) $v), $value),
            static fn (string $v): bool => $v !== '',
        ));
    }

    private function textOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function uuidOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return preg_match('/^[0-9a-f-]{36}$/i', $value) === 1 ? $value : null;
    }

    private function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
