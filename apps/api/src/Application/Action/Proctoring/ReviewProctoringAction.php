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

        return $this->page($request, $response, $tenantId, $assessment->id);
    }

    /**
     * GET /v1/proctoring/queue — everything waiting on a reviewer.
     *
     * Across every assessment. `?pending=1` drops the sittings already
     * signed off, which is what makes it a queue rather than a log.
     */
    public function allQueues(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::ProctoringReview);

        return $this->page($request, $response, $this->mustBeEntitled($request), null);
    }

    private function page(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $tenantId,
        ?string $assessmentId,
    ): ResponseInterface {
        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['perPage'] ?? 20)));

        $result = $this->proctoring->reviewQueue(
            $tenantId,
            $assessmentId,
            $page,
            $perPage,
            filter_var($query['pending'] ?? false, FILTER_VALIDATE_BOOL),
        );

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

    /**
     * PUT /v1/attempts/{id}/proctoring/review  body { status, notes }
     *
     * A verdict on the whole sitting — cleared or flagged — with notes. Distinct
     * from reviewing individual events: this is the reviewer signing the sitting
     * off. `pending` reopens it (e.g. to just amend the notes).
     *
     * @param array<string, string> $args
     */
    public function reviewSession(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::ProctoringReview);

        $tenantId = $this->mustBeEntitled($request);
        $attempt = $this->requireAttempt($request, $args['id'] ?? '');
        $session = $this->requireSession($tenantId, $attempt->id);

        $body = $this->body($request);
        $status = strtolower(trim((string) ($body['status'] ?? '')));

        if (!in_array($status, ['pending', 'cleared', 'flagged'], true)) {
            throw ValidationFailed::fromArray(['status' => 'Choose cleared, flagged or pending.']);
        }

        $notes = isset($body['notes']) && is_string($body['notes']) && trim($body['notes']) !== ''
            ? trim($body['notes'])
            : null;

        $updated = $this->proctoring->reviewSession(
            $tenantId,
            $session->id,
            $status,
            $notes,
            $this->userId($request),
        );

        if ($updated === null) {
            throw new EntityNotFound('Proctoring session', $session->id);
        }

        return Json::ok($response, $updated->toArray());
    }

    /**
     * GET /v1/attempts/{id}/proctoring/analytics
     *
     * The per-sitting breakdown: violations by type and severity, the snapshot
     * tallies and the trust score.
     *
     * @param array<string, string> $args
     */
    public function analytics(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::ProctoringReview);

        $tenantId = $this->mustBeEntitled($request);
        $attempt = $this->requireAttempt($request, $args['id'] ?? '');
        $session = $this->requireSession($tenantId, $attempt->id);

        return Json::ok($response, $this->proctoring->sessionAnalytics($tenantId, $session->id));
    }

    /**
     * GET /v1/attempts/{id}/proctoring/export
     *
     * The whole sitting as a downloadable evidence bundle — the session, its
     * trust score, every violation and every snapshot's metadata (with the URLs
     * of the frames) — so a reviewer can archive it or attach it to a case.
     *
     * @param array<string, string> $args
     */
    public function export(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::ProctoringReview);

        $tenantId = $this->mustBeEntitled($request);
        $attempt = $this->requireAttempt($request, $args['id'] ?? '');
        $session = $this->requireSession($tenantId, $attempt->id);

        $bundle = [
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'session'    => $session->toArrayForReview(),
            'attempt'    => $attempt->toArray(),
            'trust'      => $this->proctoring->trustFor($tenantId, $session->id)->toArray(),
            'analytics'  => $this->proctoring->sessionAnalytics($tenantId, $session->id),
            'violations' => $this->proctoring->violationsFor($tenantId, $session->id),
            'snapshots'  => $this->proctoring->snapshotsFor($tenantId, $session->id),
        ];

        $response->getBody()->write(json_encode($bundle, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader(
                'Content-Disposition',
                sprintf('attachment; filename="proctoring-%s.json"', substr($session->id, 0, 8)),
            );
    }

    /**
     * PUT /v1/proctoring/snapshots/{snapshotId}/flag  body { flagged, reason }
     *
     * Flags or unflags one captured frame for review.
     *
     * @param array<string, string> $args
     */
    public function flagSnapshot(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::ProctoringReview);

        $tenantId = $this->mustBeEntitled($request);
        $snapshotId = $args['snapshotId'] ?? '';

        // A missing snapshot is a 404, not a silent no-op that reads as success.
        if ($this->proctoring->findSnapshotSession($tenantId, $snapshotId) === null) {
            throw new EntityNotFound('Snapshot', $snapshotId);
        }

        $body = $this->body($request);
        $flagged = filter_var($body['flagged'] ?? true, FILTER_VALIDATE_BOOL);
        $reason = isset($body['reason']) && is_string($body['reason']) && trim($body['reason']) !== ''
            ? trim($body['reason'])
            : null;

        $this->proctoring->flagSnapshot($tenantId, $snapshotId, $flagged, $reason);

        return Json::ok($response, [
            'snapshotId' => $snapshotId,
            'flagged'    => $flagged,
            'flagReason' => $flagged ? $reason : null,
        ]);
    }
}
