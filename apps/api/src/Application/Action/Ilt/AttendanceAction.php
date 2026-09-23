<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Ilt;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Ilt\AttendanceMode;
use Central\Api\Domain\Ilt\AttendanceRecord;
use Central\Api\Domain\Ilt\AttendanceStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who turned up.
 *
 * GET  /v1/trainings/{id}/sessions/{sessionId}/attendance   the register
 * PUT  /v1/trainings/{id}/sessions/{sessionId}/attendance   mark somebody
 * POST /v1/trainings/{id}/sessions/{sessionId}/attendance/sync  read the provider
 * POST /v1/trainings/{id}/sessions/{sessionId}/check-in     a learner scanning
 *
 * Every write recomputes the learner's attendance across the programme in the
 * same transaction, so the figure that decides a certificate never disagrees
 * with the sessions underneath it.
 */
final class AttendanceAction extends TrainingAction
{
    /**
     * @param array<string, string> $args
     */
    public function register(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltAttendanceMark);

        $tenantId = $this->tenantId($request);
        $session = $this->requireSession($request, $args['id'] ?? '', $args['sessionId'] ?? '');

        return Json::ok($response, [
            'sessionId'  => $session->id,
            'status'     => $session->status->value,
            'locked'     => $session->attendanceLocked,
            'attendance' => $this->trainings->attendanceFor($tenantId, $session->id),
        ]);
    }

    /**
     * An instructor marking somebody.
     *
     * @param array<string, string> $args
     */
    public function mark(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltAttendanceMark);

        $tenantId = $this->tenantId($request);
        $trainingId = $args['id'] ?? '';
        $training = $this->requireTraining($request, $trainingId);
        $session = $this->requireSession($request, $trainingId, $args['sessionId'] ?? '');

        if ($session->attendanceLocked) {
            throw new ConflictingState('This register has been signed off.', 'attendance_locked');
        }

        $body = $this->body($request);
        $userId = trim((string) ($body['userId'] ?? ''));

        if ($userId === '') {
            throw ValidationFailed::fromArray(['userId' => 'Who are you marking?']);
        }

        $status = AttendanceStatus::tryFrom(strtolower(trim((string) ($body['status'] ?? ''))));

        if ($status === null) {
            throw ValidationFailed::fromArray([
                'status' => 'Choose one of: ' . implode(', ', array_map(
                    static fn (AttendanceStatus $s): string => $s->value,
                    AttendanceStatus::cases(),
                )),
            ]);
        }

        $mode = $this->resolveMode($session->delivery->allowedModes(), $body['mode'] ?? null);

        $reason = isset($body['reason']) && is_string($body['reason']) && trim($body['reason']) !== ''
            ? trim($body['reason'])
            : null;

        if ($reason === null) {
            // An override with no reason is indistinguishable from a scan
            // later on, which is exactly what an auditor asks about.
            throw ValidationFailed::fromArray([
                'reason' => 'Say why you are marking this by hand.',
            ]);
        }

        try {
            $result = $this->trainings->mark(
                $tenantId,
                $session,
                $training,
                $userId,
                $status,
                $mode,
                [
                    'isOverride'     => true,
                    'overrideBy'     => $this->userId($request),
                    'overrideReason' => $reason,
                ],
            );
        } catch (\RuntimeException $e) {
            throw new ConflictingState($e->getMessage(), 'not_enrolled');
        }

        return Json::ok($response, [
            'attendance' => $result['attendance'],
            'summary'    => $result['summary']->toArray(),
        ]);
    }

    /**
     * A learner scanning the code at the door.
     *
     * @param array<string, string> $args
     */
    public function checkIn(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentSelf);

        $tenantId = $this->tenantId($request);
        $userId = $this->userId($request);
        $trainingId = $args['id'] ?? '';
        $training = $this->requireTraining($request, $trainingId);
        $session = $this->requireSession($request, $trainingId, $args['sessionId'] ?? '');

        $token = trim((string) ($this->body($request)['token'] ?? ''));

        if ($token === '') {
            throw ValidationFailed::fromArray(['token' => 'Scan the code shown at the session.']);
        }

        $now = new \DateTimeImmutable();

        if (!$session->qrIsValid($token, $now, $training->qrValidBeforeStartMinutes)) {
            // One message for a wrong code and an expired one: telling them
            // apart lets somebody test codes until one is accepted.
            throw new ConflictingState(
                'That code is not valid for this session.',
                'invalid_check_in_code',
            );
        }

        if ($session->attendanceLocked) {
            throw new ConflictingState('This register has been signed off.', 'attendance_locked');
        }

        // Late is still attendance — see AttendanceStatus::countsAsAttended().
        $status = $session->isLate($now) ? AttendanceStatus::Late : AttendanceStatus::Present;

        try {
            $result = $this->trainings->mark(
                $tenantId,
                $session,
                $training,
                $userId,
                $status,
                AttendanceMode::Physical,
                ['qrScannedAt' => $now->format(\DateTimeInterface::ATOM)],
            );
        } catch (\RuntimeException $e) {
            throw new ConflictingState($e->getMessage(), 'not_enrolled');
        }

        return Json::ok($response, [
            'status'  => $status->value,
            'message' => $status === AttendanceStatus::Late
                ? 'Checked in, marked as late.'
                : 'Checked in.',
            'summary' => $result['summary']->toArray(),
        ]);
    }

    /**
     * A learner scanning a code without knowing which session it belongs to.
     *
     * POST /v1/me/trainings/check-in  {"token": "…"}
     *
     * The distinction from {@see checkIn} is the whole point: that one is
     * reached at `/trainings/{id}/sessions/{sessionId}/check-in`, which a
     * scanner cannot build. Somebody standing at a door has a code and
     * nothing else — no training id, no session id — so the code has to be
     * the thing that identifies the session. `findSessionByQrToken` has been
     * on the repository since it was written and nothing has ever called it;
     * this is what it was for.
     *
     * The token is still checked against the session it resolves to, so a
     * code that has expired is refused even though it matched a row.
     */
    public function scan(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::EnrollmentSelf);

        $tenantId = $this->tenantId($request);
        $userId   = $this->userId($request);
        $token    = trim((string) ($this->body($request)['token'] ?? ''));

        if ($token === '') {
            throw ValidationFailed::fromArray(['token' => 'Scan the code shown at the session.']);
        }

        // Scoped to the caller's tenant, so a code from another organisation
        // resolves to nothing here rather than to their session.
        $session = $this->trainings->findSessionByQrToken($tenantId, $token);
        $now     = new \DateTimeImmutable();
        // The training carries the QR window (valid-before-start), so it is
        // loaded before the validity check rather than after it.
        $training = $session === null ? null : $this->trainings->find($tenantId, $session->trainingId);

        if ($session === null || $training === null
            || !$session->qrIsValid($token, $now, $training->qrValidBeforeStartMinutes)) {
            // One message for a code that does not exist, one from elsewhere
            // and one that has expired. Telling them apart lets somebody try
            // codes until one is accepted.
            throw new ConflictingState(
                'That code is not valid for any session running now.',
                'invalid_check_in_code',
            );
        }

        if ($session->attendanceLocked) {
            throw new ConflictingState('This register has been signed off.', 'attendance_locked');
        }

        // Late is still attendance — see AttendanceStatus::countsAsAttended().
        $status = $session->isLate($now) ? AttendanceStatus::Late : AttendanceStatus::Present;

        try {
            $result = $this->trainings->mark(
                $tenantId,
                $session,
                $training,
                $userId,
                $status,
                AttendanceMode::Physical,
                ['qrScannedAt' => $now->format(\DateTimeInterface::ATOM)],
            );
        } catch (\RuntimeException $e) {
            throw new ConflictingState($e->getMessage(), 'not_enrolled');
        }

        return Json::ok($response, [
            'sessionId' => $session->id,
            'title'     => $session->title,
            'status'    => $status->value,
            'message'   => $status === AttendanceStatus::Late
                ? 'Checked in, marked as late.'
                : 'Checked in.',
            'summary'   => $result['summary']->toArray(),
        ]);
    }
    /**
     * Reads attendance back from the meeting provider.
     *
     * The join and leave times come from Zoom or Teams rather than from the
     * learner's browser: a self-reported duration on something that decides a
     * certification is a number the learner controls.
     *
     * @param array<string, string> $args
     */
    public function sync(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltAttendanceMark);

        $tenantId = $this->tenantId($request);
        $trainingId = $args['id'] ?? '';
        $training = $this->requireTraining($request, $trainingId);
        $session = $this->requireSession($request, $trainingId, $args['sessionId'] ?? '');

        if (!$session->hasMeeting()) {
            throw new ConflictingState(
                'This session has no meeting to read attendance from.',
                'no_meeting',
            );
        }

        $result = $this->scheduler->fetchAttendance($session);

        if ($result['error'] !== null) {
            throw new ConflictingState($result['error'], 'attendance_sync_failed');
        }

        // Matched by email: the provider knows an address, not a user id. Page
        // through the whole roster — rosterFor caps a page at 200, so a single
        // call silently dropped every learner past the 200th in a large class.
        $byEmail = [];
        $page = 1;
        do {
            $rosterPage = $this->trainings->rosterFor($tenantId, $training->id, $page, 200);
            foreach ($rosterPage['items'] as $row) {
                if (is_string($row['userEmail'] ?? null) && $row['userEmail'] !== '') {
                    $byEmail[strtolower($row['userEmail'])] = (string) $row['userId'];
                }
            }
            $page++;
        } while (($page - 1) * 200 < (int) ($rosterPage['total'] ?? 0));

        $matched = 0;
        $unmatched = [];

        foreach ($result['records'] as $record) {
            $email = $record->email === null ? null : strtolower($record->email);
            $userId = $email === null ? null : ($byEmail[$email] ?? null);

            if ($userId === null) {
                // Someone joined who is not on the roster, or joined under a
                // personal address. Reported rather than dropped: it is the
                // kind of thing an organiser wants to know about.
                $unmatched[] = $record->displayName;

                continue;
            }

            $this->trainings->mark(
                $tenantId,
                $session,
                $training,
                $userId,
                // Provider-reported minutes against the programme's threshold.
                $record->meetsThreshold($training->minVirtualDurationMinutes)
                    ? AttendanceStatus::Present
                    : AttendanceStatus::Absent,
                AttendanceMode::Virtual,
                [
                    'joinedAt'        => $record->joinedAt->format(\DateTimeInterface::ATOM),
                    'leftAt'          => $record->leftAt?->format(\DateTimeInterface::ATOM),
                    'durationMinutes' => $record->durationMinutes,
                    'providerData'    => $record->toArray(),
                ],
            );

            $matched++;
        }

        return Json::ok($response, [
            'sessionId'          => $session->id,
            'participantsRead'   => count($result['records']),
            'matched'            => $matched,
            'unmatched'          => $unmatched,
            'minimumMinutes'     => $training->minVirtualDurationMinutes,
            'attendance'         => $this->trainings->attendanceFor($tenantId, $session->id),
        ]);
    }

    /**
     * @param list<AttendanceMode> $allowed
     *
     * @throws ValidationFailed
     */
    private function resolveMode(array $allowed, mixed $raw): AttendanceMode
    {
        if ($raw === null || $raw === '') {
            return $allowed[0];
        }

        $mode = AttendanceMode::tryFrom(strtolower(trim((string) $raw)));

        if ($mode === null || !in_array($mode, $allowed, true)) {
            throw ValidationFailed::fromArray([
                'mode' => 'This session allows: ' . implode(', ', array_map(
                    static fn (AttendanceMode $m): string => $m->value,
                    $allowed,
                )) . '.',
            ]);
        }

        return $mode;
    }
}
