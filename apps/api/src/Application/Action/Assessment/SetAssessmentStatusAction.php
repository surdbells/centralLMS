<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Assessment;
use Central\Api\Domain\Assessment\AssessmentStatus;
use Central\Api\Domain\Assessment\QuestionSelectionMode;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /v1/assessments/{id}/status
 */
final class SetAssessmentStatusAction extends AssessmentAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $tenantId = $this->tenantId($request);
        $assessment = $this->requireAssessment($request, $args['id'] ?? '');

        $requested = strtolower(trim((string) ($this->body($request)['status'] ?? '')));
        $status = AssessmentStatus::tryFrom($requested);

        if ($status === null) {
            throw ValidationFailed::fromArray([
                'status' => 'Choose one of: ' . implode(', ', array_map(
                    static fn (AssessmentStatus $s): string => $s->value,
                    AssessmentStatus::cases(),
                )),
            ]);
        }

        if ($status === AssessmentStatus::Published) {
            $this->mustBePublishable($tenantId, $assessment);
        }

        $updated = $this->assessments->setStatus($tenantId, $assessment->id, $status);

        return $updated === null
            ? throw new EntityNotFound('Assessment', $assessment->id)
            : Json::ok($response, $updated->toArray());
    }

    /**
     * Refuses to publish an assessment that could not produce a paper.
     *
     * Each mode fails differently, and each failure is silent at runtime:
     * an empty assessment scores everyone zero out of zero, which the pass
     * rules read as a pass; a random count larger than the pool quietly
     * shortens every paper; a rule asking for more than its bank holds does
     * the same. The author should hear about all three now, not from a
     * learner afterwards.
     */
    private function mustBePublishable(string $tenantId, Assessment $assessment): void
    {
        if ($assessment->questionSelection === QuestionSelectionMode::Pool) {
            $rules = $this->assessments->rulesFor($tenantId, $assessment->id);

            if ($rules === []) {
                throw new ConflictingState(
                    'This assessment draws its questions by rule, but has no rules yet.',
                    'assessment_no_rules',
                );
            }

            foreach ($rules as $rule) {
                if (!$rule->isSatisfiable()) {
                    throw new ConflictingState(
                        sprintf(
                            'A rule asks for %d questions but only %d match it.',
                            $rule->questionCount,
                            $rule->availableCount,
                        ),
                        'rule_unsatisfiable',
                    );
                }
            }

            return;
        }

        $questions = $this->assessments->questionsFor($tenantId, $assessment->id);

        if ($questions === []) {
            throw new ConflictingState('This assessment has no questions yet.', 'assessment_empty');
        }

        if (
            $assessment->questionSelection === QuestionSelectionMode::Random
            && $assessment->randomQuestionCount !== null
            && $assessment->randomQuestionCount > count($questions)
        ) {
            throw new ConflictingState(
                sprintf(
                    'It draws %d questions but only %d are attached.',
                    $assessment->randomQuestionCount,
                    count($questions),
                ),
                'not_enough_questions',
            );
        }
    }
}
