<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\AssessmentStatus;
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
            $questions = $this->assessments->questionsFor($tenantId, $assessment->id);

            if ($questions === []) {
                // An assessment with no questions scores everyone zero out of
                // zero, which the pass rules then read as a pass.
                throw new ConflictingState(
                    'This assessment has no questions yet.',
                    'assessment_empty',
                );
            }
        }

        $updated = $this->assessments->setStatus($tenantId, $assessment->id, $status);

        return $updated === null
            ? throw new EntityNotFound('Assessment', $assessment->id)
            : Json::ok($response, $updated->toArray());
    }
}
