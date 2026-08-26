<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Proctoring;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Proctoring\ProctoringSettings;
use Central\Api\Domain\Proctoring\SessionStatus;
use Central\Api\Domain\Proctoring\ViolationSeverity;
use Central\Api\Domain\Proctoring\ViolationType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The learner's own side of a proctored sitting.
 *
 * POST /v1/attempts/{id}/proctoring/session    open it, or resume
 * POST /v1/attempts/{id}/proctoring/check      report the pre-flight result
 * POST /v1/attempts/{id}/proctoring/violations report an event
 * POST /v1/attempts/{id}/proctoring/snapshots  store a frame
 *
 * All four need only `assessment.take` — they are called by the browser of the
 * person being examined — but every one is scoped to that person's own
 * attempt. Reporting a violation against somebody else's sitting would
 * otherwise be a way to sabotage it.
 */
final class ProctoringSessionAction extends ProctoringAction
{
    /**
     * @param array<string, string> $args
     */
    public function open(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentTake);

        $tenantId = $this->mustBeEntitled($request);
        $attempt = $this->requireAttempt($request, $args['id'] ?? '');
        $this->mustOwn($request, $attempt);

        $assessment = $this->requireAssessment($request, $attempt->assessmentId);
        $settings = $this->settingsFor($tenantId, $attempt->assessmentId, $assessment->proctoringLevel);

        if (!$assessment->proctoringLevel->isEnabled()) {
            throw new ConflictingState(
                'This assessment is not proctored.',
                'not_proctored',
            );
        }

        $session = $this->proctoring->openSession($tenantId, $attempt->id, $attempt->userId);

        return Json::ok($response, [
            'session'  => $session->toArray(),
            'settings' => $settings->toArray(),
            'requiredPermissions' => $settings->requiredPermissions(),
            // What still stands between the learner and starting.
            'outstandingChecks' => $session->outstandingChecks($settings),
            // Said plainly, because being recorded is something to be told
            // about rather than to discover.
            'notice' => $settings->capturesRecordings()
                ? 'This assessment is recorded. Your camera, screen or microphone may be captured for review.'
                : 'This assessment is monitored. Your activity in the browser is recorded as events.',
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function check(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentTake);

        $tenantId = $this->mustBeEntitled($request);
        $attempt = $this->requireAttempt($request, $args['id'] ?? '');
        $this->mustOwn($request, $attempt);

        $session = $this->requireSession($tenantId, $attempt->id);
        $assessment = $this->requireAssessment($request, $attempt->assessmentId);
        $settings = $this->settingsFor($tenantId, $attempt->assessmentId, $assessment->proctoringLevel);

        $body = $this->body($request);

        if (!array_key_exists('passed', $body)) {
            throw ValidationFailed::fromArray(['passed' => 'Say whether the checks passed.']);
        }

        $passed = filter_var($body['passed'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($passed === null) {
            throw ValidationFailed::fromArray(['passed' => 'Send true or false.']);
        }

        $changes = [
            'systemCheckPassed' => $passed,
            'systemCheckAt'     => new \DateTimeImmutable(),
            'systemCheckData'   => is_array($body['data'] ?? null) ? $body['data'] : null,
            'status'            => $passed ? SessionStatus::Active->value : SessionStatus::SystemCheck->value,
        ];

        // Identity and room scan arrive on the same call when the level asks
        // for them, so the learner is not walked through three round trips.
        if (array_key_exists('idVerified', $body)) {
            $changes['idVerified'] = filter_var($body['idVerified'], FILTER_VALIDATE_BOOL);
            $changes['idVerificationAt'] = new \DateTimeImmutable();
        }

        foreach (['idPhotoUrl', 'facePhotoUrl', 'roomScanVideoUrl'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && trim($body[$key]) !== '') {
                $changes[$key] = trim($body[$key]);
            }
        }

        if (array_key_exists('faceMatchScore', $body) && is_numeric($body['faceMatchScore'])) {
            $changes['faceMatchScore'] = (float) $body['faceMatchScore'];
        }

        if (array_key_exists('roomScanCompleted', $body)) {
            $changes['roomScanCompleted'] = filter_var($body['roomScanCompleted'], FILTER_VALIDATE_BOOL);
        }

        if ($passed) {
            $changes['startedAt'] = $session->startedAt ?? new \DateTimeImmutable();
        }

        $updated = $this->proctoring->updateSession($tenantId, $session->id, $changes);
        $outstanding = $updated->outstandingChecks($settings);

        // Only clear once nothing is outstanding: passing the browser check
        // while identity is still unverified is not ready.
        if ($outstanding !== [] && $updated->status === SessionStatus::Active) {
            $updated = $this->proctoring->updateSession($tenantId, $session->id, [
                'status' => SessionStatus::SystemCheck->value,
            ]);
        }

        return Json::ok($response, [
            'session'           => $updated->toArray(),
            'outstandingChecks' => $outstanding,
            'mayBegin'          => $outstanding === [] && $updated->status === SessionStatus::Active,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function violation(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentTake);

        $tenantId = $this->mustBeEntitled($request);
        $attempt = $this->requireAttempt($request, $args['id'] ?? '');
        $this->mustOwn($request, $attempt);

        $session = $this->requireSession($tenantId, $attempt->id);

        if (!$session->status->acceptsEvents()) {
            throw new ConflictingState(
                'This proctoring session is not running.',
                'session_not_active',
            );
        }

        $body = $this->body($request);
        $rawType = trim((string) ($body['type'] ?? ''));

        try {
            $type = ViolationType::fromLegacy($rawType);
        } catch (\ValueError) {
            throw ValidationFailed::fromArray(['type' => sprintf('"%s" is not a known event.', $rawType)]);
        }

        // The severity the client sends is a hint at most: the weighting is
        // ours, and a browser that could set its own would be able to report
        // everything as trivial.
        $severity = $type->defaultSeverity();

        $result = $this->proctoring->recordViolation(
            $tenantId,
            $session,
            $type,
            $severity,
            isset($body['description']) && is_string($body['description']) ? trim($body['description']) : null,
            isset($body['questionId']) && is_string($body['questionId']) && $body['questionId'] !== ''
                ? $body['questionId']
                : null,
            is_array($body['data'] ?? null) ? $body['data'] : [],
        );

        return Json::ok($response, [
            'recorded' => true,
            'type'     => $type->value,
            'severity' => $severity->value,
            'action'   => $result['action'],
            // Told to the learner only when the event is one they can act on;
            // see ViolationType::warnsTheLearner().
            'warning'  => $type->warnsTheLearner()
                ? sprintf('%s was recorded by the proctor.', $type->label())
                : null,
            // The score itself is deliberately not returned: a learner who can
            // watch it fall can find the threshold by experiment.
            'sittingEnded' => $result['action'] === 'terminated',
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function snapshot(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentTake);

        $tenantId = $this->mustBeEntitled($request);
        $attempt = $this->requireAttempt($request, $args['id'] ?? '');
        $this->mustOwn($request, $attempt);

        $session = $this->requireSession($tenantId, $attempt->id);

        if (!$session->status->acceptsEvents()) {
            throw new ConflictingState('This proctoring session is not running.', 'session_not_active');
        }

        $body = $this->body($request);
        $imageUrl = trim((string) ($body['imageUrl'] ?? ''));

        if ($imageUrl === '') {
            throw ValidationFailed::fromArray(['imageUrl' => 'Where the frame was stored.']);
        }

        $id = $this->proctoring->recordSnapshot($tenantId, $session, [
            'kind'     => in_array($body['kind'] ?? '', [
                'periodic', 'violation', 'question_start', 'question_submit', 'manual',
            ], true) ? $body['kind'] : 'periodic',
            'imageUrl'      => $imageUrl,
            'thumbnailUrl'  => $body['thumbnailUrl'] ?? null,
            'fileSizeBytes' => isset($body['fileSizeBytes']) && is_numeric($body['fileSizeBytes'])
                ? (int) $body['fileSizeBytes']
                : null,
            'questionId'    => $body['questionId'] ?? null,
            'timeElapsedSeconds' => isset($body['timeElapsedSeconds']) && is_numeric($body['timeElapsedSeconds'])
                ? max(0, (int) $body['timeElapsedSeconds'])
                : 0,
            'faceDetected'   => $body['faceDetected'] ?? null,
            'faceCount'      => isset($body['faceCount']) && is_numeric($body['faceCount'])
                ? max(0, (int) $body['faceCount'])
                : null,
            'faceConfidence' => isset($body['faceConfidence']) && is_numeric($body['faceConfidence'])
                ? (float) $body['faceConfidence']
                : null,
            'facePosition'    => is_array($body['facePosition'] ?? null) ? $body['facePosition'] : null,
            'gazeDirection'   => is_array($body['gazeDirection'] ?? null) ? $body['gazeDirection'] : null,
            'objectsDetected' => is_array($body['objectsDetected'] ?? null) ? $body['objectsDetected'] : null,
            'flagged'         => filter_var($body['flagged'] ?? false, FILTER_VALIDATE_BOOL),
            'flagReason'      => $body['flagReason'] ?? null,
        ]);

        return Json::created($response, ['id' => $id, 'recorded' => true]);
    }

    private function settingsFor(
        string $tenantId,
        string $assessmentId,
        \Central\Api\Domain\Assessment\ProctoringLevel $fallbackLevel,
    ): ProctoringSettings {
        $stored = $this->proctoring->settingsFor($tenantId, $assessmentId);

        return $stored['settings'] ?? ProctoringSettings::forLevel($fallbackLevel);
    }
}
