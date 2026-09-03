<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Library;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Library\DigitalLibraryItem;
use Central\Api\Domain\Library\DigitalLibraryRepository;
use Central\Api\Infrastructure\Security\JwtTokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The digital library as a viewer sees it.
 *
 * Everyone may open the library (`library.view`); what they see is only what is
 * shared with them, decided by each item's audience against them now. The
 * ticket that lets a browser fetch the bytes is minted here, and only for an
 * item the caller can actually see — so the audience is enforced at the door,
 * not merely reflected in the listing.
 */
final class LibraryAction
{
    public function __construct(
        private readonly DigitalLibraryRepository $library,
        private readonly JwtTokenService $tokens,
    ) {
    }

    /** The items shared with the caller. */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::LibraryView);

        [$page, $perPage] = $this->paging($request);
        $result = $this->library->listVisibleTo(
            $this->tenantId($request),
            $caller->id,
            $this->str($request->getQueryParams()['search'] ?? null),
            $page,
            $perPage,
        );

        return Json::page(
            $response,
            array_map(static fn (DigitalLibraryItem $i): array => $i->toArray(), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    /**
     * A ticket to fetch one item's bytes, for a viewer who may see it.
     *
     * @param array<string, string> $args
     */
    public function ticket(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::LibraryView);

        $tenantId = $this->tenantId($request);
        $item = $this->library->find($tenantId, $args['id']);

        // A viewer may only ticket what is shared with them; a manager may
        // ticket anything, to preview it. Either way an item they cannot see is
        // a 404, not a refusal that admits it exists.
        $mayView = $caller->can(Permission::LibraryManage)
            || $this->library->isVisibleTo($tenantId, $args['id'], $caller->id);

        if ($item === null || !$mayView) {
            throw new EntityNotFound('Library item', $args['id']);
        }

        if (!$item->isViewable()) {
            // A document still converting has nothing to show yet.
            throw new EntityNotFound('Library file', $args['id']);
        }

        $ticket = $this->tokens->issueMediaTicket($caller->id, $tenantId, (string) $item->id);

        return Json::ok($response, [
            'url'       => sprintf('/v1/library/%s/file?ticket=%s', $item->id, rawurlencode($ticket)),
            'ticket'    => $ticket,
            'expiresIn' => $this->tokens->mediaTicketTtl(),
        ]);
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);
        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::LibraryView->value);
        }

        return $user->tenantId;
    }

    /**
     * @return array{int, int}
     */
    private function paging(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            max(1, (int) ($query['page'] ?? 1)),
            max(1, min(200, (int) ($query['perPage'] ?? 50))),
        ];
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
