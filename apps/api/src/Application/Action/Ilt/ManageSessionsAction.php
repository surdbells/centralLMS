<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Ilt;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Ilt\InstructorRole;
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
 * GET   /v1/trainings/{id}/sessions/{sessionId}/instructors
 * POST  /v1/trainings/{id}/sessions/{sessionId}/instructors
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

        // The reminders the programme asks for, and a note to the enrolled
        // that there is now something in their diary. Both are best-effort:
        // a session that exists but was not announced beats one that was not
        // created because the mail provider was down.
        $this->notifier->syncReminders($training, $created);
        $this->notifier->announceScheduled($training, $created);

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

        if ($moved) {
            // Nobody has to recompute a reminder time: the schedules hold an
            // offset, so they follow the session. This tells the enrolled.
            $this->notifier->announceMoved($training, $updated);
        } else {
            // Still re-synced, because the programme's offsets may have been
            // edited even when the session's own times were not.
            $this->notifier->syncReminders($training, $updated);
        }

        // Only when it actually moved: rescheduling on every save would churn
        // the provider and re-notify every attendee for a typo fix.
        if ($moved && $existing->hasMeeting()) {
            $result = $this->scheduler->reschedule($updated);

            if ($result['meeting'] !== null) {
                $updated = $this->trainings->saveMeeting(
                    $tenantId,
                    $updated->id,
                    $updated->platform,
                    $result['meeting'],
                    $result['error'],
                ) ?? $updated;
            } else {
                // The reschedule failed. The existing booking is still valid and
                // in people's calendars, so keep it and only record the error —
                // wiping it would leave an empty join button at the new time.
                $updated = $this->trainings->recordMeetingError(
                    $tenantId,
                    $updated->id,
                    $result['error'],
                ) ?? $updated;
            }
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

    // --- who teaches it ---------------------------------------------------

    /**
     * Everybody teaching this session, the lead first.
     *
     * @param array<string, string> $args
     */
    public function instructors(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionView);

        $session = $this->requireSession($request, $args['id'] ?? '', $args['sessionId'] ?? '');

        return Json::ok($response, $this->trainings->instructorsFor(
            $this->tenantId($request),
            $session->id,
        ));
    }

    /**
     * Puts somebody on a session, or changes what they do on it.
     *
     * @param array<string, string> $args
     */
    public function assignInstructor(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $session = $this->requireSession($request, $args['id'] ?? '', $args['sessionId'] ?? '');
        $body = $this->body($request);

        $userId = trim((string) ($body['userId'] ?? ''));

        if ($userId === '') {
            throw ValidationFailed::fromArray(['userId' => 'Choose somebody.']);
        }

        $role = InstructorRole::tryFrom((string) ($body['role'] ?? 'primary'));

        if ($role === null) {
            throw ValidationFailed::fromArray([
                'role' => 'Choose one of: ' . implode(', ', InstructorRole::values()),
            ]);
        }

        $assigned = $this->trainings->assignInstructor(
            $tenantId,
            $session->id,
            $userId,
            $role,
            (bool) ($body['isHost'] ?? false),
        );

        if (!$assigned) {
            throw new EntityNotFound('Session', $session->id);
        }

        return Json::ok($response, $this->trainings->instructorsFor($tenantId, $session->id));
    }

    /**
     * @param array<string, string> $args
     */
    public function removeInstructor(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $session = $this->requireSession($request, $args['id'] ?? '', $args['sessionId'] ?? '');
        $userId = $args['userId'] ?? '';

        if (!$this->trainings->removeInstructor($tenantId, $session->id, $userId)) {
            throw new EntityNotFound('Instructor', $userId);
        }

        // No promotion afterwards. A session left with no lead is visibly
        // wrong on the screen, which is the right place for somebody to decide
        // who takes over.
        return Json::ok($response, $this->trainings->instructorsFor($tenantId, $session->id));
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
        if ($status === SessionStatus::Cancelled) {
            if ($session->hasMeeting()) {
                $error = $this->scheduler->cancel($session);
                $updated = $this->trainings->saveMeeting($tenantId, $session->id, null, null, $error) ?? $updated;
            }

            // Pending reminders are stopped first, then everyone is told.
            // A learner who is not told may still turn up; one who is
            // reminded to attend a cancelled session certainly will.
            $this->notifier->announceCancelled(
                $this->requireTraining($request, $trainingId),
                $updated,
                is_string($this->body($request)['reason'] ?? null)
                    ? (string) $this->body($request)['reason']
                    : null,
            );
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
