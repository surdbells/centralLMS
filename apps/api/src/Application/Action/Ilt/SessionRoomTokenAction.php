<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Ilt;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Ilt\AttendanceMode;
use Central\Api\Domain\Ilt\AttendanceStatus;
use Central\Api\Domain\Ilt\MeetingProviderId;
use Central\Api\Domain\Ilt\TrainingRepository;
use Central\Api\Domain\People\PersonRepository;
use Central\Api\Infrastructure\Ilt\MeetingProviderFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Issues the per-user RTC + RTM tokens needed to open a session's in-app (Agora)
 * room, and records a learner's attendance as they enter — the in-app
 * equivalent of walking through the door.
 *
 * GET /v1/ilt/sessions/{sessionId}/room-token
 *
 * Authorised by the session's own membership, not a blanket permission: a token
 * is minted only for a session you teach or are enrolled on. It is short-lived
 * and bound to the caller's own account, so it cannot be handed to anyone else.
 *
 * @phpstan-type Args array{sessionId?: string}
 */
final class SessionRoomTokenAction
{
    public function __construct(
        private readonly TrainingRepository $trainings,
        private readonly MeetingProviderFactory $providers,
        private readonly PersonRepository $people,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = AuthenticationMiddleware::require($request);

        $tenantId = $user->tenantId;
        if ($tenantId === null) {
            throw new AccessDenied(Permission::IltSessionView->value);
        }
        $userId = $user->id;

        $sessionId = $args['sessionId'] ?? '';
        $session = $this->trainings->findSession($tenantId, $sessionId)
            ?? throw new EntityNotFound('Session', $sessionId);

        if ($session->platform !== MeetingProviderId::Agora || $session->externalMeetingId === null) {
            throw new ConflictingState(
                'This session is not an in-app video session.',
                'not_in_app_session',
            );
        }

        if (!$this->providers->isAgoraAvailable()) {
            throw new ConflictingState(
                'In-app video is not configured for this deployment.',
                'agora_unconfigured',
            );
        }

        // Membership: you may take a token only for a session you teach or are on.
        $isInstructor = false;
        foreach ($this->trainings->instructorsFor($tenantId, $session->id) as $instructor) {
            if (($instructor['userId'] ?? null) === $userId) {
                $isInstructor = true;
                break;
            }
        }
        $isEnrolled = $this->trainings->findEnrolment($tenantId, $session->trainingId, $userId) !== null;

        if (!$isInstructor && !$isEnrolled) {
            throw new AccessDenied(Permission::IltSessionView->value);
        }

        $channel     = $session->externalMeetingId;
        $ttl         = $this->providers->agoraTokenTtl();
        $builder     = $this->providers->agoraTokenBuilder();
        $displayName = $this->people->find($tenantId, $userId)?->fullName() ?: $user->email;

        // A learner opening the room is attendance — recorded once, best-effort,
        // never blocking the join, never for an instructor or a signed-off list.
        if ($isEnrolled && !$isInstructor && !$session->attendanceLocked) {
            $training = $this->trainings->find($tenantId, $session->trainingId);
            if ($training !== null) {
                $now = new \DateTimeImmutable();
                try {
                    $this->trainings->mark(
                        $tenantId,
                        $session,
                        $training,
                        $userId,
                        $session->isLate($now) ? AttendanceStatus::Late : AttendanceStatus::Present,
                        AttendanceMode::Virtual,
                        ['joinedAt' => $now->format(\DateTimeInterface::ATOM), 'via' => 'agora'],
                    );
                } catch (\RuntimeException) {
                    // Attendance is a side effect of joining, not a gate on it.
                }
            }
        }

        return Json::ok($response, [
            'appId'            => $this->providers->agoraAppId(),
            'channel'          => $channel,
            'uid'              => $userId,
            'displayName'      => $displayName,
            'rtcToken'         => $builder->rtcToken($channel, $userId, $ttl),
            'rtmToken'         => $builder->rtmToken($userId, $ttl),
            'role'             => $isInstructor ? 'host' : 'audience',
            'isHost'           => $isInstructor,
            'expiresInSeconds' => $ttl,
            'sessionTitle'     => $session->title,
        ]);
    }
}
