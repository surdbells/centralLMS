<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\QuestionBank;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/question-banks
 */
final class ListBanksAction extends AssessmentAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $listing = $this->listing($request);
        $result = $this->questions->paginateBanks(
            $this->tenantId($request),
            $listing['page'],
            $listing['perPage'],
            $listing['search'],
        );

        return Json::page(
            $response,
            array_map(static fn (QuestionBank $b): array => $b->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }
}
