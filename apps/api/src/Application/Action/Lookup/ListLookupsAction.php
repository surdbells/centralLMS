<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Lookup;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Lookup\Lookup;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/lookups/{kind}
 *
 * Paginated administration list. Replaces six `list.php` files — the ones that
 * ran `SELECT * FROM branches` with no tenant filter, so every organisation
 * could see every other organisation's structure.
 */
final class ListLookupsAction extends LookupAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireView($request);

        $kind = $this->kind($args);
        $query = $request->getQueryParams();

        $result = $this->lookups->paginate(
            $this->tenantId($request),
            $kind,
            page: max(1, (int) ($query['page'] ?? 1)),
            perPage: (int) ($query['perPage'] ?? 20),
            search: isset($query['search']) ? (string) $query['search'] : null,
            sortBy: (string) ($query['sortBy'] ?? 'name'),
            sortDirection: (string) ($query['sortDir'] ?? 'asc'),
        );

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['perPage'] ?? 20)));

        return Json::page(
            $response,
            array_map(static fn (Lookup $l): array => $l->toArray(), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }
}
