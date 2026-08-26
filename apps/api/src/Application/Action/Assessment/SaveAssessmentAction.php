<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Assessment;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * POST /v1/assessments and PUT /v1/assessments/{id}
 *
 * Creating always yields a draft: a `status` in the body is ignored, exactly
 * as for courses, so nothing can be published without going through the
 * publish route and its permission.
 */
final class SaveAssessmentAction extends AssessmentAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $tenantId = $this->tenantId($request);
        $id = $args['id'] ?? null;
        $existing = $id === null ? null : $this->requireAssessment($request, $id);

        if ($existing !== null && !$existing->status->isEditable()) {
            throw new ConflictingState(
                'A closed or archived assessment cannot be edited. Move it back to draft first.',
                'assessment_not_editable',
            );
        }

        $assessment = Assessment::fromInput(
            $existing->id ?? Uuid::uuid4()->toString(),
            $tenantId,
            $existing === null
                ? [...$this->body($request), 'status' => 'draft']
                : [
                    ...$existing->toArray(),
                    ...$this->body($request),
                    // Preserved: publishing is setStatus()'s job.
                    'status' => $existing->status->value,
                ],
        );

        if ($existing === null) {
            // Passed through whole rather than rebuilt argument by argument:
            // see Assessment::withCreatedBy().
            $created = $this->assessments->create($assessment->withCreatedBy($this->userId($request)));

            return Json::created($response, $created->toArray(), '/v1/assessments/' . $created->id);
        }

        return Json::ok($response, $this->assessments->update($assessment)->toArray());
    }
}
