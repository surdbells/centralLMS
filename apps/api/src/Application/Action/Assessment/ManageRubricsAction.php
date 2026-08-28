<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Assessment\Rubric;
use Central\Api\Domain\Assessment\RubricCriterion;
use Central\Api\Domain\Assessment\RubricLevel;
use Central\Api\Domain\Assessment\RubricRepository;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Marking rubrics.
 *
 * A rubric is what makes an essay mark repeatable: the criteria a marker
 * should judge, and for each, the bands they choose between. The screen for
 * this existed with no table behind it.
 *
 * Criteria are written whole rather than one at a time. A rubric edited
 * criterion by criterion can be observed mid-edit totalling something nobody
 * intended, and a marker opening it at that moment marks against it.
 */
final class ManageRubricsAction
{
    public function __construct(private readonly RubricRepository $rubrics)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::AssessmentGrade);

        $activeOnly = ($request->getQueryParams()['activeOnly'] ?? null) === '1';

        return Json::ok($response, array_map(
            static fn (Rubric $r): array => $r->toArray(),
            $this->rubrics->all($this->tenantId($request), $activeOnly),
        ));
    }

    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentGrade);

        return Json::ok($response, $this->require($request, $args['id'] ?? '')->toArray());
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::AssessmentManage);

        $tenantId = $this->tenantId($request);
        $body = $this->body($request);

        $rubric = Rubric::fromInput(
            $this->uuid(),
            $tenantId,
            $body,
            $caller->id,
        );

        $criteria = $this->criteriaFromInput($tenantId, $rubric->id, $body);

        return Json::created(
            $response,
            $this->rubrics->save($rubric->withCriteria($criteria))->toArray(),
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $tenantId = $this->tenantId($request);
        $existing = $this->require($request, $args['id'] ?? '');
        $body = $this->body($request);

        $rubric = new Rubric(
            id: $existing->id,
            tenantId: $tenantId,
            name: trim((string) ($body['name'] ?? $existing->name)),
            description: array_key_exists('description', $body)
                ? (is_string($body['description']) ? trim($body['description']) : null)
                : $existing->description,
            kind: $existing->kind,
            isActive: (bool) ($body['isActive'] ?? $existing->isActive),
            createdBy: $existing->createdBy,
        );

        $saved = $this->rubrics->save($rubric);

        // Only when the caller sent them: a PATCH that says nothing about
        // criteria must not silently empty them.
        if (array_key_exists('criteria', $body)) {
            $saved = $this->rubrics->replaceCriteria(
                $tenantId,
                $saved->id,
                $this->criteriaFromInput($tenantId, $saved->id, $body),
            );
        }

        return Json::ok($response, $saved->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $tenantId = $this->tenantId($request);
        $rubric = $this->require($request, $args['id'] ?? '');

        if ($rubric->questionCount > 0) {
            // Refused rather than cascaded: the marks already given against
            // this rubric name its criteria, and removing it while questions
            // still point at it leaves those scores unexplainable.
            throw new ConflictingState(
                sprintf(
                    'This rubric is used by %d question(s). Detach it from those first.',
                    $rubric->questionCount,
                ),
                'rubric_in_use',
            );
        }

        $this->rubrics->delete($tenantId, $rubric->id);

        return Json::noContent($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function duplicate(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::AssessmentManage);

        $tenantId = $this->tenantId($request);
        $original = $this->require($request, $args['id'] ?? '');

        $name = trim((string) ($this->body($request)['name'] ?? ''));

        if ($name === '') {
            $name = $original->name . ' (copy)';
        }

        $copy = $this->rubrics->duplicate($tenantId, $original->id, $name, $caller->id);

        if ($copy === null) {
            throw new EntityNotFound('Rubric', $original->id);
        }

        return Json::created($response, $copy->toArray());
    }

    // --- internals ----------------------------------------------------------

    /**
     * The criteria and their bands, out of the request.
     *
     * @param array<string, mixed> $body
     *
     * @return list<RubricCriterion>
     *
     * @throws ValidationFailed
     */
    private function criteriaFromInput(string $tenantId, string $rubricId, array $body): array
    {
        $criteria = [];
        $position = 0;

        foreach ((array) ($body['criteria'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }

            $criterion = RubricCriterion::fromInput($this->uuid(), $tenantId, $rubricId, $line);

            $levels = [];
            $band = 0;

            foreach ((array) ($line['levels'] ?? []) as $levelInput) {
                if (!is_array($levelInput)) {
                    continue;
                }

                $violations = [];

                $levels[] = RubricLevel::fromInput(
                    $this->uuid(),
                    $tenantId,
                    $criterion->id,
                    $levelInput,
                    $violations,
                    sprintf('criteria.%d.levels.%d', $position, $band),
                );

                $band++;
            }

            $criteria[] = $criterion->withLevels($levels);
            $position++;
        }

        return $criteria;
    }

    private function require(ServerRequestInterface $request, string $id): Rubric
    {
        $rubric = $this->rubrics->find($this->tenantId($request), $id);

        if ($rubric === null) {
            throw new EntityNotFound('Rubric', $id);
        }

        return $rubric;
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::AssessmentGrade->value);
        }

        return $user->tenantId;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff),
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }
}
