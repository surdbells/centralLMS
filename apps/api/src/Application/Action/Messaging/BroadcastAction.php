<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Messaging;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Messaging\Broadcast;
use Central\Api\Domain\Messaging\BroadcastRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Announcements to an audience.
 *
 * Two audiences of its own: whoever writes them needs `notification.send`,
 * because a broadcast reaches everybody in an organisation; whoever receives
 * one needs nothing beyond being signed in, and only ever sees the ones
 * addressed to them.
 */
final class BroadcastAction
{
    public function __construct(private readonly BroadcastRepository $broadcasts)
    {
    }

    // --- writing them ---------------------------------------------------------

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::NotificationSend);

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['perPage'] ?? 25)));

        // `?mine=1` for what this person wrote; otherwise the organisation's,
        // which is what a communications team wants.
        $result = $this->broadcasts->authored(
            $this->tenantId($request),
            ($query['mine'] ?? null) === '1' ? $caller->id : null,
            $page,
            $perPage,
        );

        return Json::page(
            $response,
            array_map(static fn (Broadcast $b): array => $b->toArray(), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::NotificationSend);

        return Json::ok($response, $this->require($request, $args['id'] ?? '')->toArray());
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::NotificationSend);

        $broadcast = Broadcast::fromInput(
            $this->uuid(),
            $this->tenantId($request),
            $this->body($request),
            $caller->id,
        );

        return Json::created($response, $this->broadcasts->save($broadcast)->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::NotificationSend);

        $existing = $this->require($request, $args['id'] ?? '');

        if (!$existing->isEditable()) {
            // A sent announcement cannot be unsaid: people have read it, and
            // editing it leaves them holding a version nobody else has.
            throw new ConflictingState(
                'This announcement has already gone out and cannot be changed.',
                'broadcast_already_sent',
            );
        }

        $updated = Broadcast::fromInput(
            $existing->id,
            $existing->tenantId,
            [...$existing->toArray(), ...$this->body($request)],
            $existing->createdBy,
        );

        return Json::ok($response, $this->broadcasts->save($updated)->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::NotificationSend);

        $broadcast = $this->require($request, $args['id'] ?? '');
        $this->broadcasts->delete($this->tenantId($request), $broadcast->id);

        return Json::noContent($response);
    }

    /**
     * Sends it now.
     *
     * The audience becomes a list of people at this moment — see the
     * repository for why not later.
     *
     * @param array<string, string> $args
     */
    public function send(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::NotificationSend);

        $tenantId = $this->tenantId($request);
        $broadcast = $this->require($request, $args['id'] ?? '');

        if (!$broadcast->status->canSend()) {
            throw new ConflictingState(
                'This announcement has already gone out.',
                'broadcast_already_sent',
            );
        }

        $recipients = $this->broadcasts->send($tenantId, $broadcast->id);

        if ($recipients === 0) {
            // Refused rather than recorded: an announcement marked sent that
            // reached nobody is worse than one that failed, because nobody
            // will look at it again.
            throw new ConflictingState(
                'That audience contains nobody. Check who it is addressed to.',
                'broadcast_audience_empty',
            );
        }

        return Json::ok($response, [
            ...($this->broadcasts->find($tenantId, $broadcast->id)?->toArray() ?? []),
            'sentTo' => $recipients,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function cancel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::NotificationSend);

        $tenantId = $this->tenantId($request);
        $broadcast = $this->require($request, $args['id'] ?? '');

        if (!$this->broadcasts->cancel($tenantId, $broadcast->id)) {
            throw new ConflictingState(
                'Only a draft or a scheduled announcement can be cancelled.',
                'broadcast_not_cancellable',
            );
        }

        return Json::ok($response, $this->broadcasts->find($tenantId, $broadcast->id)?->toArray());
    }

    // --- receiving them -------------------------------------------------------

    /** GET /v1/me/broadcasts — what has been sent to the caller. */
    public function mine(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$tenantId, $userId] = $this->me($request);

        return Json::ok($response, $this->broadcasts->forRecipient(
            $tenantId,
            $userId,
            ($request->getQueryParams()['unreadOnly'] ?? null) === '1',
        ));
    }

    /** GET /v1/me/broadcasts/unread — the badge. */
    public function unread(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$tenantId, $userId] = $this->me($request);

        return Json::ok($response, ['total' => $this->broadcasts->unreadCount($tenantId, $userId)]);
    }

    /**
     * @param array<string, string> $args
     */
    public function mineShow(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->me($request);

        $broadcast = $this->broadcasts->findForRecipient($tenantId, $userId, $args['id'] ?? '');

        if ($broadcast === null) {
            // A 404 rather than a 403: whether an announcement exists is not
            // something somebody it was not sent to should be able to probe.
            throw new EntityNotFound('Broadcast', $args['id'] ?? '');
        }

        return Json::ok($response, $broadcast);
    }

    /**
     * @param array<string, string> $args
     */
    public function markRead(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->me($request);

        $this->broadcasts->markRead($tenantId, $userId, $args['id'] ?? '');

        return Json::noContent($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function acknowledge(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->me($request);
        $id = $args['id'] ?? '';

        if ($this->broadcasts->findForRecipient($tenantId, $userId, $id) === null) {
            throw new EntityNotFound('Broadcast', $id);
        }

        $this->broadcasts->acknowledge($tenantId, $userId, $id);

        return Json::ok($response, $this->broadcasts->findForRecipient($tenantId, $userId, $id));
    }

    // --- internals ------------------------------------------------------------

    private function require(ServerRequestInterface $request, string $id): Broadcast
    {
        return $this->broadcasts->find($this->tenantId($request), $id)
            ?? throw new EntityNotFound('Broadcast', $id);
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::NotificationSend->value);
        }

        return $user->tenantId;
    }

    /**
     * @return array{string, string}
     */
    private function me(ServerRequestInterface $request): array
    {
        $caller = $this->caller($request);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::EnrollmentSelf->value);
        }

        return [$caller->tenantId, $caller->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff),
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }
}
