<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Ilt;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Ilt\MeetingProviderId;
use Central\Api\Domain\Ilt\SessionStatus;
use Central\Api\Domain\Ilt\TrainingSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * Sessions, and the meetings behind them.
 *
 * POST  /v1/trainings/{id}/sessions
 * PUT   /v1/trainings/{id}/sessions/{sessionId}
 * PATCH /v1/trainings/{id}/sessions/{sessionId}/status
 * POST  /v1/trainings/{id}/sessions/{sessionId}/meeting   book or retry
 * POST  /v1/trainings/{id}/sessions/{sessionId}/qr        issue a door code
 *
 * Creating an online session books a real meeting through the provider layer.
 * That is the change: the legacy code returned `https://meet.example.com/`
 * plus random hex and never called the providers it had already written.
 */
final class ManageSessionsAction extends TrainingAction
{
    /**
     * @param array<string, string> $args
     */
    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $training = $this->requireTraining($request, $args['id'] ?? '');
        $this->mustBeEditable($training);

        $session = TrainingSession::fromInput(
            Uuid::uuid4()->toString(),
            $tenantId,
            $training->id,
            $training->delivery,
            $this->body($request),
        );

        $created = $this->trainings->createSession($session);

        // Booked immediately, and allowed to fail without failing the session.
        $created = $this->bookMeeting($request, $created) ?? $created;

        return Json::created(
            $response,
            $created->toArray(includeHostUrl: true),
            sprintf('/v1/trainings/%s/sessions/%s', $training->id, $created->id),
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $trainingId = $args['id'] ?? '';
        $training = $this->requireTraining($request, $trainingId);
        $this->mustBeEditable($training);

        $existing = $this->requireSession($request, $trainingId, $args['sessionId'] ?? '');

        if ($existing->status === SessionStatus::Completed) {
            throw new ConflictingState(
                'A completed session cannot be moved.',
                'session_completed',
            );
        }

        $session = TrainingSession::fromInput(
            $existing->id,
            $tenantId,
            $training->id,
            $training->delivery,
            [...$existing->toArray(), 'startsAt' => $existing->startsAt->format(\DateTimeInterface::ATOM),
                'endsAt' => $existing->endsAt->format(\DateTimeInterface::ATOM),
                'venueName' => $existing->venueName,
                'venueAddress' => $existing->venueAddress,
                'venueRoom' => $existing->venueRoom,
                'venueCapacity' => $existing->venueCapacity,
                'venueMapUrl' => $existing->venueMapUrl,
                ...$this->body($request)],
        );

        $updated = $this->trainings->updateSession($session);
        $moved = $updated->startsAt != $existing->startsAt || $updated->endsAt != $existing->endsAt;

        // Only when it actually moved: rescheduling on every save would churn
        // the provider and re-notify every attendee for a typo fix.
        if ($moved && $existing->hasMeeting()) {
            $result = $this->scheduler->reschedule($updated);
            $updated = $this->trainings->saveMeeting(
                $tenantId,
                $updated->id,
                $updated->platform,
                $result['meeting'],
                $result['error'],
            ) ?? $updated;
        } elseif ($updated->needsMeeting()) {
            // It became online, or the first booking failed.
            $updated = $this->bookMeeting($request, $updated) ?? $updated;
        }

        return Json::ok($response, $updated->toArray(includeHostUrl: true));
    }

    /**
     * Books the meeting, or retries after a failure.
     *
     * @param array<string, string> $args
     */
    public function meeting(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $trainingId = $args['id'] ?? '';
        $session = $this->requireSession($request, $trainingId, $args['sessionId'] ?? '');

        if (!$session->delivery->needsMeeting()) {
            throw new ConflictingState(
                'This session is in person, so there is no meeting to book.',
                'session_is_physical',
            );
        }

        if ($session->hasMeeting()) {
            throw new ConflictingState(
                'This session already has a meeting. Change its time to move it.',
                'meeting_exists',
            );
        }

        $updated = $this->bookMeeting($request, $session);

        if ($updated === null || !$updated->hasMeeting()) {
            // The failure is the answer, and it is the provider's, so it is a
            // 409 with what they said rather than a 500.
            throw new ConflictingState(
                $updated === null
                    ? 'The meeting could not be scheduled.'
                    : ($updated->meetingError ?? 'The meeting could not be scheduled.'),
                'meeting_failed',
            );
        }

        return Json::ok($response, $updated->toArray(includeHostUrl: true));
    }

    /**
     * @param array<string, string> $args
     */
    public function setStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $trainingId = $args['id'] ?? '';
        $session = $this->requireSession($request, $trainingId, $args['sessionId'] ?? '');

        $requested = strtolower(trim((string) ($this->body($request)['status'] ?? '')));
        $status = SessionStatus::tryFrom($requested);

        if ($status === null) {
            throw ValidationFailed::fromArray([
                'status' => 'Choose one of: ' . implode(', ', array_map(
                    static fn (SessionStatus $s): string => $s->value,
                    SessionStatus::cases(),
                )),
            ]);
        }

        $updated = $this->trainings->setSessionStatus($tenantId, $session->id, $status);

        if ($updated === null) {
            throw new EntityNotFound('Session', $session->id);
        }

        // Cancelling takes the meeting down with it, so the link in everyone's
        // calendar stops working rather than quietly admitting people.
        if ($status === SessionStatus::Cancelled && $session->hasMeeting()) {
            $error = $this->scheduler->cancel($session);
            $updated = $this->trainings->saveMeeting($tenantId, $session->id, null, null, $error) ?? $updated;
        }

        return Json::ok($response, $updated->toArray(includeHostUrl: true));
    }

    /**
     * @param array<string, string> $args
     */
    public function issueQr(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltAttendanceMark);

        $tenantId = $this->tenantId($request);
        $trainingId = $args['id'] ?? '';
        $training = $this->requireTraining($request, $trainingId);
        $session = $this->requireSession($request, $trainingId, $args['sessionId'] ?? '');

        if (!$session->delivery->needsVenue()) {
            throw new ConflictingState(
                'This session is online, so there is no door to scan at.',
                'session_is_virtual',
            );
        }

        $updated = $this->trainings->issueQrToken($tenantId, $session->id, $training);

        if ($updated === null) {
            // The one legacy token expired six hours before it was generated.
            // Refusing to mint a code for a session already past its window is
            // how that stops happening.
            throw new ConflictingState(
                'This session is already past its attendance window.',
                'attendance_window_closed',
            );
        }

        return Json::ok($response, [
            'sessionId' => $updated->id,
            'token'     => $updated->qrToken,
            'expiresAt' => $updated->qrExpiresAt?->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Books a meeting and records the outcome, success or failure.
     */
    private function bookMeeting(ServerRequestInterface $request, TrainingSession $session): ?TrainingSession
    {
        if (!$session->needsMeeting()) {
            return null;
        }

        $rawPlatform = $this->body($request)['platform'] ?? null;
        $platform = is_string($rawPlatform) && $rawPlatform !== ''
            ? MeetingProviderId::tryFrom($rawPlatform)
            : null;

        $result = $this->scheduler->schedule($session, $platform);

        return $this->trainings->saveMeeting(
            $this->tenantId($request),
            $session->id,
            $result['platform'],
            $result['meeting'],
            $result['error'],
        );
    }
}
