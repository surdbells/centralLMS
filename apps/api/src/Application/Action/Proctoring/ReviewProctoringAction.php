<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Proctoring;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The reviewer's side.
 *
 * GET /v1/assessments/{id}/proctoring/queue      sittings needing a person
 * GET /v1/attempts/{id}/proctoring               one sitting in full
 * PUT /v1/proctoring/violations/{violationId}    a verdict on one event
 *
 * Requires `proctoring.review`, which is separate from both authoring and
 * marking: this is the only permission that grants access to webcam frames and
 * recordings of learners, and it should be given to fewer people than either.
 */
final class ReviewProctoringAction extends ProctoringAction
{
    /**
     * @param array<string, string> $args
     */
    public function queue(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::ProctoringReview);

        $tenantId = $this->mustBeEntitled($request);
        $assessment = $this->requireAssessment($request, $args['id'] ?? '');

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['perPage'] ?? 20)));

        $result = $this->proctoring->reviewQueue($tenantId, $assessment->id, $page, $perPage);

        return Json::page($response, $result['items'], $result['total'], $page, $perPage);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::ProctoringReview);

        $tenantId = $this->mustBeEntitled($request);
        $attempt = $this->requireAttempt($request, $args['id'] ?? '');
        $session = $this->requireSession($tenantId, $attempt->id);

        $trust = $this->proctoring->trustFor($tenantId, $session->id);

        return Json::ok($response, [
            // The review payload, which is the only one carrying recordings.
            'session'    => $session->toArrayForReview(),
            'attempt'    => $attempt->toArray(),
            'trust'      => $trust->toArray(),
            'violations' => $this->proctoring->violationsFor($tenantId, $session->id),
            'snapshots'  => $this->proctoring->snapshotsFor(
                $tenantId,
                $session->id,
                // Everything is a lot of frames; the flagged ones are what a
                // reviewer opens on.
                flaggedOnly: filter_var(
                    $request->getQueryParams()['flaggedOnly'] ?? false,
                    FILTER_VALIDATE_BOOL,
                ),
            ),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function review(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::ProctoringReview);

        $tenantId = $this->mustBeEntitled($request);
        $violationId = $args['violationId'] ?? '';

        $body = $this->body($request);
        $status = strtolower(trim((string) ($body['status'] ?? '')));

        if (!in_array($status, ['valid', 'false_positive', 'inconclusive'], true)) {
            throw ValidationFailed::fromArray([
                'status' => 'Choose valid, false_positive or inconclusive.',
            ]);
        }

        $notes = isset($body['notes']) && is_string($body['notes']) && trim($body['notes']) !== ''
            ? trim($body['notes'])
            : null;

        $reviewed = $this->proctoring->reviewViolation(
            $tenantId,
            $violationId,
            $status,
            $notes,
            $this->userId($request),
        );

        if (!$reviewed) {
            throw new EntityNotFound('Violation', $violationId);
        }

        return Json::ok($response, [
            'violationId' => $violationId,
            'status'      => $status,
            // Marking something a false positive removes it from the score,
            // which is the point of reviewing at all.
            'note' => $status === 'false_positive'
                ? 'This event no longer counts against the sitting.'
                : null,
        ]);
    }
}
