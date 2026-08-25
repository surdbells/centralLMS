<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Lookup;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/lookups/{kind}/{id}
 */
final class ShowLookupAction extends LookupAction
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
        $id = $args['id'] ?? '';

        // Scoped by tenant, so an id belonging to another organisation is a
        // 404 rather than a read — the legacy `single.php` looked up by id
        // alone.
        $lookup = $this->lookups->find($this->tenantId($request), $kind, $id);

        if ($lookup === null) {
            throw new EntityNotFound($kind->label(), $id);
        }

        return Json::ok($response, $lookup->toArray());
    }
}
