<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Domain\Assessment\RandomRule;
use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * Rules for `pool` selection: "draw N from this bank, of this difficulty".
 *
 * GET    /v1/assessments/{id}/rules
 * POST   /v1/assessments/{id}/rules
 * DELETE /v1/assessments/{id}/rules/{ruleId}
 *
 * Each rule reports how many questions actually match it, so an author can see
 * that a rule asking for ten when six qualify will shorten every paper —
 * before a learner meets the short one.
 */
final class ManageRulesAction extends AssessmentAction
{
    /**
     * @param array<string, string> $args
     */
    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $assessment = $this->requireAssessment($request, $args['id'] ?? '');
        $rules = $this->assessments->rulesFor($this->tenantId($request), $assessment->id);

        return Json::ok($response, [
            'rules' => array_map(static fn (RandomRule $r): array => $r->toArray(), $rules),
            // The size of paper these rules would produce, and whether they
            // can actually produce it.
            'questionsPerPaper' => array_sum(array_map(
                static fn (RandomRule $r): int => $r->questionCount,
                $rules,
            )),
            'allSatisfiable' => array_reduce(
                $rules,
                static fn (bool $carry, RandomRule $r): bool => $carry && $r->isSatisfiable(),
                true,
            ),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->editable($request, $args);
        $assessmentId = $args['id'] ?? '';

        $rule = RandomRule::fromInput(
            Uuid::uuid4()->toString(),
            $tenantId,
            $assessmentId,
            $this->body($request),
        );

        // Resolved in this tenant so an id from elsewhere is a 404 rather
        // than a foreign-key error.
        if ($this->questions->findBank($tenantId, $rule->bankId) === null) {
            throw new EntityNotFound('Question bank', $rule->bankId);
        }

        if (
            $rule->sectionId !== null
            && $this->assessments->findSection($tenantId, $assessmentId, $rule->sectionId) === null
        ) {
            throw new EntityNotFound('Section', $rule->sectionId);
        }

        $created = $this->assessments->createRule($rule);

        return Json::created(
            $response,
            $created->toArray(),
            sprintf('/v1/assessments/%s/rules/%s', $assessmentId, $created->id),
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
        $tenantId = $this->editable($request, $args);
        $ruleId = $args['ruleId'] ?? '';

        return $this->assessments->deleteRule($tenantId, $args['id'] ?? '', $ruleId)
            ? Json::noContent($response)
            : throw new EntityNotFound('Rule', $ruleId);
    }

    /**
     * @param array<string, string> $args
     */
    private function editable(ServerRequestInterface $request, array $args): string
    {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $assessment = $this->requireAssessment($request, $args['id'] ?? '');

        if (!$assessment->status->isEditable()) {
            throw new ConflictingState(
                'A published assessment cannot be restructured. Move it back to draft first.',
                'assessment_not_editable',
            );
        }

        return $this->tenantId($request);
    }
}
