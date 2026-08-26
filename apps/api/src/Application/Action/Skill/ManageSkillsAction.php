<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Skill;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Skill\LearnerSkill;
use Central\Api\Domain\Skill\Proficiency;
use Central\Api\Domain\Skill\Skill;
use Central\Api\Domain\Skill\SkillCategory;
use Central\Api\Domain\Skill\SkillFilters;
use Central\Api\Domain\Skill\SkillRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Skills, their categories, and who holds them.
 *
 * GET    /v1/skills
 * POST   /v1/skills
 * GET    /v1/skills/statistics
 * GET    /v1/skills/categories
 * POST   /v1/skills/categories
 * PUT    /v1/skills/categories/{id}
 * DELETE /v1/skills/categories/{id}
 * GET    /v1/skills/{id}
 * PUT    /v1/skills/{id}
 * DELETE /v1/skills/{id}
 * GET    /v1/skills/{id}/courses
 * GET    /v1/skills/{id}/learners
 * GET    /v1/courses/{id}/skills
 * PUT    /v1/courses/{id}/skills
 * GET    /v1/me/skills
 * GET    /v1/users/{id}/skills
 *
 * Sixteen routes for the legacy's twenty-five actions, and the reduction is
 * almost entirely the five `my-skills` / `user-skills` variants collapsing into
 * two: what somebody holds and what they are working towards are one query
 * with a flag, not two that disagree.
 *
 * **A learner's skills are derived.** `user_skills_acquired` was built to store
 * them, holds nothing, and was never read — `getLearnerSkills()` joins
 * completed enrolments to `course_skills`. See the migration for what that
 * cannot express and what each of those would need.
 */
final class ManageSkillsAction
{
    public function __construct(private readonly SkillRepository $skills)
    {
    }

    // --- the dictionary ---------------------------------------------------

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->readable($request);

        $violations = [];
        $filters    = SkillFilters::fromQuery($request->getQueryParams(), $violations);

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $listing = $this->listing($request);
        $result  = $this->skills->paginate($tenantId, $filters, $listing['page'], $listing['perPage']);

        return Json::page(
            $response,
            array_map(static fn (Skill $s): array => $s->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    public function statistics(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::ok($response, $this->skills->statistics($this->readable($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->readable($request);

        return Json::ok($response, $this->requireSkill($tenantId, $args['id'])->toArray());
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->writable($request);
        $skill    = $this->skillFrom($request, $tenantId, null);

        return Json::created($response, $this->skills->save($skill)->toArray(), '/v1/skills/' . $skill->id);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->writable($request);
        $existing = $this->requireSkill($tenantId, $args['id']);

        return Json::ok(
            $response,
            $this->skills->save($this->skillFrom($request, $tenantId, $existing))->toArray(),
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->writable($request);

        if (!$this->skills->softDelete($tenantId, $args['id'])) {
            throw new EntityNotFound('Skill', $args['id']);
        }

        return Json::noContent($response);
    }

    // --- categories -------------------------------------------------------

    public function categories(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId   = $this->readable($request);
        $activeOnly = filter_var($request->getQueryParams()['active'] ?? false, FILTER_VALIDATE_BOOL);

        return Json::ok(
            $response,
            array_map(
                static fn (SkillCategory $c): array => $c->toArray(),
                $this->skillsCategories($tenantId, $activeOnly),
            ),
        );
    }

    public function createCategory(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->writable($request);
        $category = $this->categoryFrom($request, $tenantId, null);

        return Json::created($response, $this->skills->saveCategory($category)->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function updateCategory(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->writable($request);
        $existing = $this->skills->findCategory($tenantId, $args['id'])
            ?? throw new EntityNotFound('Skill category', $args['id']);

        return Json::ok(
            $response,
            $this->skills->saveCategory($this->categoryFrom($request, $tenantId, $existing))->toArray(),
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteCategory(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->writable($request);

        if (!$this->skills->deleteCategory($tenantId, $args['id'])) {
            throw new EntityNotFound('Skill category', $args['id']);
        }

        return Json::noContent($response);
    }

    // --- what teaches what ------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function coursesTeaching(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->readable($request);
        $this->requireSkill($tenantId, $args['id']);

        return Json::ok($response, $this->skills->coursesTeaching($tenantId, $args['id']));
    }

    /**
     * @param array<string, string> $args
     */
    public function forCourse(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->readable($request);

        return Json::ok($response, $this->skills->forCourse($tenantId, $args['id']));
    }

    /**
     * Replaces what a course teaches.
     *
     * @param array<string, string> $args
     */
    public function setCourseSkills(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        // Authoring a course, not maintaining the dictionary — so this is the
        // one write here that a course author may make.
        $caller = $this->caller($request);
        $caller->mustHave(Permission::CourseManage);

        $tenantId = $this->tenantOf($caller);
        $body     = (array) ($request->getParsedBody() ?? []);
        $given    = $body['skills'] ?? [];

        if (!is_array($given)) {
            throw ValidationFailed::fromArray(['skills' => 'Send a list of skills.']);
        }

        $violations = [];
        $accepted   = [];
        $seen       = [];
        $primaries  = 0;

        foreach (array_values($given) as $index => $entry) {
            $entry   = is_array($entry) ? $entry : ['skillId' => $entry];
            $skillId = trim((string) ($entry['skillId'] ?? ''));

            if ($this->skills->find($tenantId, $skillId) === null) {
                // Covers a malformed id, one from another organisation, and a
                // deleted skill. A course cannot teach any of them.
                $violations['skills.' . $index] = 'There is no such skill.';

                continue;
            }

            if (isset($seen[$skillId])) {
                $violations['skills.' . $index] = 'That skill is listed twice.';

                continue;
            }

            $proficiency = Proficiency::tryFrom(strtolower(trim((string) ($entry['proficiency'] ?? 'intermediate'))));

            if ($proficiency === null) {
                $violations['skills.' . $index] = 'Choose one of: ' . implode(', ', Proficiency::values()) . '.';

                continue;
            }

            $isPrimary = filter_var($entry['isPrimary'] ?? false, FILTER_VALIDATE_BOOL);

            if ($isPrimary) {
                $primaries++;
            }

            $seen[$skillId] = true;
            $accepted[]     = [
                'skillId'     => $skillId,
                'proficiency' => $proficiency->value,
                'isPrimary'   => $isPrimary,
            ];
        }

        if ($primaries > 1) {
            // A course cannot be "really about" four things. The index enforces
            // it too; saying so here is the difference between a 422 and a 500.
            $violations['skills'] = 'Only one skill can be the main one.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return Json::ok($response, $this->skills->setCourseSkills($tenantId, $args['id'], $accepted));
    }

    // --- who holds what ---------------------------------------------------

    /**
     * The caller's own. No id in the path, so there is nothing to tamper with.
     */
    public function mine(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = $this->caller($request);

        return Json::ok($response, $this->skillsFor(
            $this->tenantOf($caller),
            $caller->id,
            $request,
        ));
    }

    /**
     * Somebody else's, which is a people-management question.
     *
     * @param array<string, string> $args
     */
    public function forUser(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);

        if ($args['id'] !== $caller->id) {
            $caller->mustHave(Permission::UserView);
        }

        return Json::ok($response, $this->skillsFor($this->tenantOf($caller), $args['id'], $request));
    }

    /**
     * @param array<string, string> $args
     */
    public function learners(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::UserView);

        $tenantId = $this->tenantOf($caller);
        $this->requireSkill($tenantId, $args['id']);

        $listing = $this->listing($request);
        $result  = $this->skills->learnersWith($tenantId, $args['id'], $listing['page'], $listing['perPage']);

        return Json::page($response, $result['items'], $result['total'], $listing['page'], $listing['perPage']);
    }

    // --- plumbing ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function skillsFor(string $tenantId, string $userId, ServerRequestInterface $request): array
    {
        $heldOnly = filter_var($request->getQueryParams()['held'] ?? false, FILTER_VALIDATE_BOOL);
        $skills   = $this->skills->forLearner($tenantId, $userId, $heldOnly);

        $held       = array_values(array_filter($skills, static fn (LearnerSkill $s): bool => $s->isHeld()));
        $inProgress = array_values(array_filter($skills, static fn (LearnerSkill $s): bool => !$s->isHeld()));

        return [
            // Grouped once here rather than in five endpoints that each
            // decided for themselves what counted.
            'held'       => array_map(static fn (LearnerSkill $s): array => $s->toArray(), $held),
            'inProgress' => array_map(static fn (LearnerSkill $s): array => $s->toArray(), $inProgress),
            'byCategory' => $this->groupByCategory($held),
            'totals'     => [
                'held'       => count($held),
                'inProgress' => count($inProgress),
            ],
        ];
    }

    /**
     * @param  list<LearnerSkill>   $skills
     * @return list<array<string, mixed>>
     */
    private function groupByCategory(array $skills): array
    {
        $groups = [];

        foreach ($skills as $skill) {
            $key = $skill->categoryId ?? 'uncategorised';

            $groups[$key] ??= [
                'categoryId'     => $skill->categoryId,
                'categoryName'   => $skill->categoryName ?? 'Uncategorised',
                'categoryColour' => $skill->categoryColour ?? '#6c757d',
                'skills'         => [],
            ];

            $groups[$key]['skills'][] = $skill->toArray();
        }

        return array_values($groups);
    }

    /**
     * @return list<SkillCategory>
     */
    private function skillsCategories(string $tenantId, bool $activeOnly): array
    {
        return $this->skills->categories($tenantId, $activeOnly);
    }

    private function skillFrom(ServerRequestInterface $request, string $tenantId, ?Skill $existing): Skill
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? $existing->name ?? ''));

        $violations = [];

        if ($name === '') {
            $violations['name'] = 'Give the skill a name.';
        } elseif ($this->skills->nameExists($tenantId, $name, $existing?->id)) {
            // Refused rather than written: two skills with one name is a
            // dictionary nobody can use, and the index would reject it anyway.
            $violations['name'] = 'A skill with that name already exists.';
        }

        $level = Proficiency::tryFrom(strtolower(trim(
            (string) ($body['level'] ?? $existing?->level->value ?? 'intermediate'),
        )));

        if ($level === null) {
            $violations['level'] = 'Choose one of: ' . implode(', ', Proficiency::values()) . '.';
        }

        $colour = strtolower(trim((string) ($body['colour'] ?? $existing->colour ?? '#c9a227')));

        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $colour) !== 1) {
            $violations['colour'] = 'Give a colour as a hex code, like #c9a227.';
        }

        $categoryId = array_key_exists('categoryId', $body)
            ? $this->uuidOrNull($body['categoryId'])
            : $existing?->categoryId;

        if ($categoryId !== null && $this->skills->findCategory($tenantId, $categoryId) === null) {
            $violations['categoryId'] = 'There is no such category.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new Skill(
            id: $existing?->id,
            tenantId: $tenantId,
            name: mb_substr($name, 0, 150),
            level: $level ?? Proficiency::Intermediate,
            description: $this->textOrNull($body['description'] ?? $existing?->description),
            categoryId: $categoryId,
            icon: $this->textOrNull($body['icon'] ?? $existing?->icon),
            colour: $colour,
            isActive: array_key_exists('isActive', $body)
                ? filter_var($body['isActive'], FILTER_VALIDATE_BOOL)
                : ($existing->isActive ?? true),
        );
    }

    private function categoryFrom(
        ServerRequestInterface $request,
        string $tenantId,
        ?SkillCategory $existing,
    ): SkillCategory {
        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? $existing->name ?? ''));

        $violations = [];

        if ($name === '') {
            $violations['name'] = 'Give the category a name.';
        } elseif ($this->skills->categoryNameExists($tenantId, $name, $existing?->id)) {
            $violations['name'] = 'A category with that name already exists.';
        }

        $colour = strtolower(trim((string) ($body['colour'] ?? $existing->colour ?? '#6c757d')));

        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $colour) !== 1) {
            $violations['colour'] = 'Give a colour as a hex code, like #6c757d.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new SkillCategory(
            id: $existing?->id,
            tenantId: $tenantId,
            name: mb_substr($name, 0, 100),
            description: $this->textOrNull($body['description'] ?? $existing?->description),
            colour: $colour,
            isActive: array_key_exists('isActive', $body)
                ? filter_var($body['isActive'], FILTER_VALIDATE_BOOL)
                : ($existing->isActive ?? true),
        );
    }

    private function requireSkill(string $tenantId, string $id): Skill
    {
        return $this->skills->find($tenantId, $id) ?? throw new EntityNotFound('Skill', $id);
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantOf(AuthenticatedUser $caller): string
    {
        return $caller->tenantId ?? throw new AccessDenied(Permission::LookupView->value);
    }

    /**
     * Reading the dictionary is reference data, like a department list: it
     * fills a picker on a course form and a badge on a learner's profile.
     */
    private function readable(ServerRequestInterface $request): string
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::LookupView);

        return $this->tenantOf($caller);
    }

    private function writable(ServerRequestInterface $request): string
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::LookupManage);

        return $this->tenantOf($caller);
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
}
