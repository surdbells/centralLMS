<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/question-banks/statistics                     -> overall (cross-bank)
 * GET /v1/question-banks/{bankId}/questions/statistics  -> one bank
 *
 * Counts computed in the database rather than by loading a bank and tallying
 * it in the browser, so the stat cards and difficulty bar are correct across
 * every page, not just the one on screen.
 */
final class QuestionStatisticsAction extends AssessmentAction
{
    public function overall(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        return Json::ok($response, $this->questions->overallStatistics($this->tenantId($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function bank(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $bank = $this->requireBank($request, $args['bankId'] ?? '');

        return Json::ok($response, $this->questions->bankStatistics($this->tenantId($request), $bank->id));
    }
}
