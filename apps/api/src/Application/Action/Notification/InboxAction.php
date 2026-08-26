<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Notification;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Notification\Notification;
use Central\Api\Domain\Notification\NotificationType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The bell.
 *
 * GET    /v1/notifications
 * GET    /v1/notifications/unread-count
 * POST   /v1/notifications/read-all
 * POST   /v1/notifications/{id}/read
 * DELETE /v1/notifications/{id}
 *
 * Every one of these is scoped to the caller by construction: the user id
 * comes from the token and goes into the WHERE clause, and there is no user id
 * anywhere in the paths. That is what makes these the only endpoints in the
 * module needing no permission — you cannot address anybody else's inbox, so
 * there is nothing to authorise.
 *
 * The two the legacy shipped — `utility/shared/notifications.php` and
 * `mark_notifications.php` — both instantiate `new Notifications($db)` against
 * a file defining `class Notification`, singular, and call three methods that
 * exist nowhere. They fatal on every request, and both Angular top bars call
 * them.
 */
final class InboxAction extends NotificationAction
{
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $listing = $this->listing($request);
        $query   = $request->getQueryParams();

        $filters = [];

        if (filter_var($query['unreadOnly'] ?? false, FILTER_VALIDATE_BOOL)) {
            $filters['unreadOnly'] = true;
        }

        if (isset($query['type']) && is_string($query['type'])) {
            $type = NotificationType::tryFrom($query['type']);

            if ($type !== null) {
                $filters['type'] = $type;
            }
        }

        $tenantId = $this->tenantId($request);
        $userId   = $this->userId($request);

        $result = $this->notifications->inbox(
            $tenantId,
            $userId,
            $listing['page'],
            $listing['perPage'],
            $filters,
        );

        return Json::page(
            $response,
            array_map(static fn (Notification $n): array => $n->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
            // Sent with the page so the badge and the list stay in step. Two
            // round trips to render one bell is what the legacy front end did,
            // and the count it showed was routinely one behind the list.
            ['unreadCount' => $this->notifications->unreadCount($tenantId, $userId)],
        );
    }

    public function unreadCount(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::ok($response, [
            'unreadCount' => $this->notifications->unreadCount(
                $this->tenantId($request),
                $this->userId($request),
            ),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function markRead(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->tenantId($request);
        $userId   = $this->userId($request);
        $id       = $args['id'] ?? '';

        $changed = $this->notifications->markRead($tenantId, $userId, $id);

        // Zero rows changed is ambiguous — it was already read, or it is
        // somebody else's. Only the second is a 404, so the row is looked up
        // to tell them apart. Both answer the same to a caller probing for
        // ids: a notification belonging to another user is not found.
        if ($changed === 0) {
            $notification = $this->notifications->find($tenantId, $id);

            if ($notification === null || $notification->userId !== $userId) {
                throw new EntityNotFound('Notification', $id);
            }
        }

        return Json::ok($response, [
            'id'          => $id,
            'isRead'      => true,
            'unreadCount' => $this->notifications->unreadCount($tenantId, $userId),
        ]);
    }

    public function markAllRead(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->tenantId($request);
        $userId   = $this->userId($request);

        $marked = $this->notifications->markAllRead($tenantId, $userId);

        return Json::ok($response, [
            'marked'      => $marked,
            'unreadCount' => $this->notifications->unreadCount($tenantId, $userId),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->tenantId($request);
        $userId   = $this->userId($request);
        $id       = $args['id'] ?? '';

        if (!$this->notifications->softDelete($tenantId, $userId, $id)) {
            throw new EntityNotFound('Notification', $id);
        }

        return Json::ok($response, [
            'id'          => $id,
            'deleted'     => true,
            'unreadCount' => $this->notifications->unreadCount($tenantId, $userId),
        ]);
    }
}
