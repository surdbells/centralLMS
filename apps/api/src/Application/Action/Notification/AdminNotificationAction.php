<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Notification;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Notification\Notifier;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Notification\DeliveryStatus;
use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationRepository;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Domain\Notification\ScheduleStatus;
use Central\Api\Domain\Identity\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Sending to other people, and seeing whether it arrived.
 *
 * POST /v1/notifications/announcements
 * GET  /v1/notifications/deliveries
 * GET  /v1/notifications/{id}
 * GET  /v1/trainings/{trainingId}/sessions/{sessionId}/reminders
 *
 * The delivery log is the answer to "did the reminders go out", which the
 * legacy could not answer about any of its four queue tables — every row in
 * all of them still says `pending`, and there was no screen that would have
 * shown it.
 */
final class AdminNotificationAction extends NotificationAction
{
    public function __construct(
        NotificationRepository $notifications,
        private readonly Notifier $notifier,
        private readonly UserRepository $users,
    ) {
        parent::__construct($notifications);
    }

    /**
     * Sends an announcement to an audience.
     */
    public function announce(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::NotificationSend);

        $tenantId = $this->tenantId($request);
        $body     = $this->body($request);

        $violations = [];

        $title = trim((string) ($body['title'] ?? ''));
        $text  = trim((string) ($body['body'] ?? ''));

        if ($title === '') {
            $violations['title'] = 'An announcement needs a title.';
        }

        if (mb_strlen($title) > 255) {
            $violations['title'] = 'Keep the title under 255 characters.';
        }

        if ($text === '') {
            $violations['body'] = 'An announcement needs something to say.';
        }

        $audience = $this->audience($tenantId, $body, $violations);

        $channels = null;

        if (isset($body['channels'])) {
            if (!is_array($body['channels'])) {
                $violations['channels'] = 'Send a list of channels.';
            } else {
                $channels = NotificationChannel::fromList(array_values(array_map('strval', $body['channels'])));

                if ($channels === []) {
                    $violations['channels'] = 'Choose from: ' . implode(', ', NotificationChannel::values()) . '.';
                }
            }
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $notified = $this->notifier->notify(
            $tenantId,
            $audience,
            NotificationType::Announcement,
            ['title' => $title, 'body' => $text],
            null,
            $channels,
        );

        return Json::created($response, [
            'notified' => $notified,
            // Both numbers, because they differ for reasons worth seeing: a
            // deleted account, a suspended one. Reporting only the audience
            // size would claim more than happened.
            'audience' => count($audience),
            'title'    => $title,
        ]);
    }

    /**
     * The delivery log.
     */
    public function deliveries(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::NotificationAudit);

        $listing = $this->listing($request);
        $query   = $request->getQueryParams();

        $filters = [];

        if (isset($query['status']) && is_string($query['status'])) {
            $status = DeliveryStatus::tryFrom($query['status']);

            if ($status !== null) {
                $filters['status'] = $status;
            }
        }

        if (isset($query['channel']) && is_string($query['channel'])) {
            $channel = NotificationChannel::tryFrom($query['channel']);

            if ($channel !== null) {
                $filters['channel'] = $channel;
            }
        }

        $result = $this->notifications->deliveries(
            $this->tenantId($request),
            $listing['page'],
            $listing['perPage'],
            $filters,
        );

        return Json::page($response, $result['items'], $result['total'], $listing['page'], $listing['perPage']);
    }

    /**
     * One notification with where it was sent and what happened.
     *
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->tenantId($request);
        $id       = $args['id'] ?? '';

        $notification = $this->notifications->findWithDeliveries($tenantId, $id)
            ?? throw new EntityNotFound('Notification', $id);

        // Own notifications need no permission; anybody else's needs the
        // audit one. Without this the id in every list response would be a
        // way to read a colleague's notifications.
        if ($notification->userId !== $this->userId($request)) {
            $this->caller($request)->mustHave(Permission::NotificationAudit);
        }

        return Json::ok($response, $notification->toArrayWithDeliveries());
    }

    /**
     * The reminders planned for one session.
     *
     * @param array<string, string> $args
     */
    public function reminders(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionView);

        $tenantId  = $this->tenantId($request);
        $sessionId = $args['sessionId'] ?? '';

        $schedules = $this->notifications->schedulesFor($tenantId, 'training_session', $sessionId);

        return Json::ok($response, [
            'sessionId' => $sessionId,
            'reminders' => array_map(
                static fn ($schedule): array => [
                    ...$schedule->toArray(),
                    // Whether it is still going to happen, said plainly.
                    // "pending" alone does not distinguish a reminder waiting
                    // its turn from one whose session was cancelled.
                    'stillDue' => $schedule->status === ScheduleStatus::Pending,
                ],
                $schedules,
            ),
        ]);
    }

    /**
     * Who to send to.
     *
     * `userIds` names people directly; `audience: "all"` means everybody
     * active in the tenant. There is deliberately no free-form filter here —
     * an announcement targeting an arbitrary query is a report, and it should
     * go through the reporting module where the query can be reviewed.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string> $violations
     * @return list<string>
     */
    private function audience(string $tenantId, array $body, array &$violations): array
    {
        $audience = is_string($body['audience'] ?? null) ? $body['audience'] : null;

        if ($audience === 'all') {
            return $this->users->activeIds($tenantId);
        }

        $userIds = $body['userIds'] ?? null;

        if (!is_array($userIds) || $userIds === []) {
            $violations['userIds'] = 'Send a list of user ids, or "audience": "all".';

            return [];
        }

        $ids = array_values(array_filter(array_map('strval', $userIds), static fn (string $id): bool => $id !== ''));

        if ($ids === []) {
            $violations['userIds'] = 'None of those are user ids.';
        }

        if (count($ids) > 5000) {
            // A guard rail, not a limit anyone will meet: `audience: "all"`
            // exists for the whole-organisation case and does not pay the
            // cost of shipping five thousand ids through a request body.
            $violations['userIds'] = 'That is too many to name individually — use "audience": "all".';
        }

        return $ids;
    }
}
