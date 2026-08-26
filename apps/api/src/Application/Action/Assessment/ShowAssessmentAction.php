<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/assessments/{id}
 *
 * Includes the questions with their answer key, so it requires
 * `assessment.manage`. The learner's view of the same assessment is
 * `POST /v1/assessments/{id}/attempts`, which strips it.
 */
final class ShowAssessmentAction extends AssessmentAction
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

        $assessment = $this->requireAssessment($request, $args['id'] ?? '');
        $rows = $this->assessments->questionsFor($this->tenantId($request), $assessment->id);

        return Json::ok($response, $assessment->toArray([
            'questions' => array_map(
                static function (array $row): array {
                    /** @var Question $question */
                    $question = $row['question'];

                    return [
                        ...$question->toArrayForReview(),
                        'pointsOverride' => $row['pointsOverride'],
                        'pointsInThisAssessment' => $question->pointsIn($row['pointsOverride']),
                        'sortOrder'  => $row['sortOrder'],
                        'isRequired' => $row['isRequired'],
                    ];
                },
                $rows,
            ),
        ]));
    }
}
