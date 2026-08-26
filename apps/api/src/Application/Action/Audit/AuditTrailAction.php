<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Audit;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Audit\AuditEvent;
use Central\Api\Domain\Audit\AuditFilters;
use Central\Api\Domain\Audit\AuditRepository;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who did what.
 *
 * GET /v1/audit
 * GET /v1/audit/actions
 *
 * Read-only, and there is no route that writes one: entries come from
 * {@see \Central\Api\Application\Middleware\AuditMiddleware}, from what the
 * router actually matched. An endpoint that let a client post its own audit
 * entry would let a client post somebody else's.
 *
 * Scoped to the caller's own tenant from the token, never from a parameter.
 * The legacy's activity screen sent `tenant_id` in the query string.
 */
final readonly class AuditTrailAction
{
    public function __construct(private AuditRepository $audit)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->readable($request);
        $listing  = $this->listing($request);

        $violations = [];
        $filters    = AuditFilters::fromQuery($request->getQueryParams(), $violations);

        // A filter that cannot be parsed is refused rather than dropped. The
        // caller asked to narrow; handing back everything looks like a wide
        // result rather than a rejected query.
        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $result = $this->audit->paginate($tenantId, $filters, $listing['page'], $listing['perPage']);

        return Json::page(
            $response,
            array_map(static fn (AuditEvent $e): array => $e->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    /**
     * The actions this tenant's trail actually contains.
     *
     * Not every route name the application has: a filter that offers two
     * hundred options, of which four have ever occurred here, is a filter
     * nobody uses.
     */
    public function actions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::ok($response, $this->audit->actionsUsed($this->readable($request)));
    }

    private function readable(ServerRequestInterface $request): string
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::AuditLogView);

        return $caller->tenantId ?? throw new AccessDenied(Permission::AuditLogView->value);
    }

    /**
     * @return array{page: int, perPage: int}
     */
    private function listing(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            'page'    => max(1, (int) ($query['page'] ?? 1)),
            'perPage' => max(1, min(100, (int) ($query['perPage'] ?? 25))),
        ];
    }
}
