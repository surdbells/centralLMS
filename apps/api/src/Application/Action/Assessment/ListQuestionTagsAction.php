<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/question-banks/{bankId}/questions/tags
 *
 * The distinct tags in use across a bank's live questions, for the authoring
 * filter and the tag autocomplete.
 */
final class ListQuestionTagsAction extends AssessmentAction
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

        return Json::ok($response, [
            'tags' => $this->questions->distinctTags($this->tenantId($request), $bank->id),
        ]);
    }
}
