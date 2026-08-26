<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Assessment\QuestionType;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/question-banks/{bankId}/questions
 *
 * The authoring view, so it carries the answer key. There is no learner-facing
 * route onto a bank at all — learners see questions only through an attempt,
 * where `toArrayForLearner()` strips it.
 */
final class ListQuestionsAction extends AssessmentAction
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

        $bank = $this->requireBank($request, $args['bankId'] ?? '');
        $listing = $this->listing($request);

        $rawType = $request->getQueryParams()['type'] ?? null;

        $result = $this->questions->paginateQuestions(
            $this->tenantId($request),
            $bank->id,
            $listing['page'],
            $listing['perPage'],
            $listing['search'],
            is_string($rawType) && $rawType !== '' ? QuestionType::tryFrom($rawType) : null,
        );

        return Json::page(
            $response,
            array_map(static fn (Question $q): array => $q->toArrayForReview(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }
}
