<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Attempt;
use Central\Api\Domain\Authz\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/me/attempts
 *
 * The assessments somebody has sat, newest first.
 *
 * Distinct from `/assessments/{id}/attempts`, which is a marker's queue for
 * one assessment and needs `assessment.grade`. A learner reading their own
 * record has neither that permission nor a list of the assessments they have
 * sat to ask about one at a time.
 *
 * The user comes from the token, not from a parameter — the legacy took a
 * `learner_id` alongside the credentials, which is how one person read
 * another's results.
 */
final class MyAttemptsAction extends AssessmentAction
{
    /** Enough for a year of assessments without paging a report screen. */
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentTake);

        $query = $request->getQueryParams();
        $limit = (int) ($query['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $attempts = $this->attempts->mine(
            $this->tenantId($request),
            $this->userId($request),
            $limit,
        );

        return Json::ok($response, array_map(
            // The learner's own attempts, so the score is theirs to see. No
            // answer key: `revealAnswers` governs that, and it is decided per
            // assessment when the paper is handed back — not here, in a list.
            static fn (Attempt $attempt): array => $attempt->toArray(),
            $attempts,
        ));
    }
}
