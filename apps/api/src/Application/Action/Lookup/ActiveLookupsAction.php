<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Lookup;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Lookup\Lookup;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/lookups/{kind}/active
 *
 * Every active entry, ordered for display. This is what fills the dropdowns,
 * so it is unpaginated on purpose — a tenant with 300 departments still wants
 * all of them in one select.
 */
final class ActiveLookupsAction extends LookupAction
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

        $entries = $this->lookups->active($this->tenantId($request), $this->kind($args));

        return Json::ok($response, array_map(static fn (Lookup $l): array => $l->toArray(), $entries));
    }
}
