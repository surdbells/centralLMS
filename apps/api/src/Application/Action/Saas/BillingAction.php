<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Saas;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Saas\Invoice;
use Central\Api\Domain\Saas\InvoiceRepository;
use Central\Api\Domain\Saas\PlanRepository;
use Central\Api\Domain\Saas\SubscriptionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An organisation's own billing.
 *
 * The tenant-facing side of the SaaS billing layer: the administrator sees the
 * plan they are on, where the current period runs to, and their invoices. It is
 * read-only for now — changing a plan or paying an invoice arrives with the
 * payment gateway; until then those are the operator's to do. No tenant id in
 * the path: the subject is whoever is signed in, so one organisation can never
 * read another's billing.
 */
final class BillingAction
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly InvoiceRepository $invoices,
        private readonly PlanRepository $plans,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::BillingManage);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::BillingManage->value);
        }

        $tenantId = $caller->tenantId;
        $sub = $this->subscriptions->findByTenant($tenantId);
        $plan = $sub?->planId !== null ? $this->plans->find($sub->planId) : null;

        return Json::ok($response, [
            'subscription' => $sub === null ? null : ($sub->toArray() + [
                'planName'     => $plan?->name,
                'planFeatures' => (object) ($plan?->features ?? []),
            ]),
            'invoices' => array_map(static fn (Invoice $i): array => $i->toArray(), $this->invoices->forTenant($tenantId)),
        ]);
    }
}
