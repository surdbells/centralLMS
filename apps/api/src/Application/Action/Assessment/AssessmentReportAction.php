<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\AssessmentRepository;
use Central\Api\Domain\Assessment\AttemptRepository;
use Central\Api\Domain\Assessment\QuestionRepository;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The two tabs of the assessment reports screen.
 *
 * GET /v1/assessments/report/learners  — one row per learner, filterable
 * GET /v1/assessments/{id}/item-analysis — per-question difficulty/discrimination
 *
 * An authoring surface, so both need `assessment.manage` (like the stats
 * endpoint), not the generic report permissions.
 */
final class AssessmentReportAction extends AssessmentAction
{
    private const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90];

    public function __construct(
        AssessmentRepository $assessments,
        QuestionRepository $questions,
        AttemptRepository $attempts,
        private readonly Clock $clock,
    ) {
        parent::__construct($assessments, $questions, $attempts);
    }

    public function learners(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $query = $request->getQueryParams();
        $assessment = trim((string) ($query['assessment'] ?? ''));
        $category = trim((string) ($query['category'] ?? ''));
        $status = trim((string) ($query['status'] ?? ''));
        $range = trim((string) ($query['range'] ?? '30d'));

        if ($status !== '' && !in_array($status, ['passed', 'failed', 'in_progress'], true)) {
            throw ValidationFailed::fromArray(['status' => 'Choose passed, failed or in_progress.']);
        }

        // 'all' (or an unknown range) means no lower bound.
        $since = isset(self::RANGES[$range])
            ? $this->clock->now()->modify('-' . self::RANGES[$range] . ' days')
            : null;

        return Json::ok($response, $this->assessments->learnerReport(
            $this->tenantId($request),
            $assessment !== '' ? $assessment : null,
            $category !== '' ? $category : null,
            $status !== '' ? $status : null,
            $since,
        ));
    }

    /**
     * @param array<string, string> $args
     */
    public function itemAnalysis(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $assessment = $this->requireAssessment($request, $args['id'] ?? '');

        return Json::ok(
            $response,
            $this->assessments->itemAnalysis($this->tenantId($request), $assessment->id),
        );
    }
}
