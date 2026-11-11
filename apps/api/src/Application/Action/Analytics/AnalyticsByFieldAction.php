<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Analytics;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Analytics\AnalyticsRepository;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Enrolment and completion sliced by one of the tenant's custom profile fields
 * — Division, Subsidiary, Entity. The core org columns are branch/department/
 * team; these are the organisation's own custom dimensions.
 *
 * GET /v1/analytics/by-field?field=KEY — needs `report.view`.
 *
 * Returns the sliceable fields always (so the UI can offer them) and, for the
 * chosen field (defaulting to the first), completion by value.
 */
final class AnalyticsByFieldAction
{
    public function __construct(private readonly AnalyticsRepository $analytics)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::ReportView);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::ReportView->value);
        }

        $tenant = $caller->tenantId;
        $fields = $this->analytics->sliceableFields($tenant);
        $field  = trim((string) ($request->getQueryParams()['field'] ?? ''));

        if ($field === '' && $fields !== []) {
            $field = $fields[0]['key'];
        }

        return Json::ok($response, [
            'fields' => $fields,
            'field'  => $field === '' ? null : $field,
            'rows'   => $field === '' ? [] : $this->analytics->completionByCustomField($tenant, $field),
        ]);
    }
}
