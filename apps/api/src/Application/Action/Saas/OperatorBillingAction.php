<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Saas;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Application\Saas\BillingService;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Saas\Invoice;
use Central\Api\Domain\Saas\InvoiceRepository;
use Central\Api\Domain\Saas\PlanRepository;
use Central\Api\Domain\Saas\Subscription;
use Central\Api\Domain\Saas\SubscriptionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The operator's billing console.
 *
 * Platform figures — recurring revenue, what is owed, the invoice run — and, per
 * organisation, its subscription and invoices with the levers over both: move
 * its billing cycle, cancel or revive it, raise an invoice, and mark one paid or
 * void. Super admin throughout; a tenant sees only its own through
 * {@see \Central\Api\Application\Action\Saas\BillingAction}.
 */
final class OperatorBillingAction
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly SubscriptionRepository $subscriptions,
        private readonly InvoiceRepository $invoices,
        private readonly PlanRepository $plans,
    ) {
    }

    /** Platform-wide billing figures. */
    public function overview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireOperator($request);

        return Json::ok($response, [
            'mrr'           => $this->subscriptions->monthlyRecurringRevenue(),
            'subscriptions' => $this->subscriptions->countByStatus(),
            'invoices'      => $this->invoices->platformTotals(),
            'recentInvoices' => $this->invoices->recentWithTenant(25),
        ]);
    }

    /**
     * One organisation's subscription and invoices.
     *
     * @param array<string, string> $args
     */
    public function tenant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        return Json::ok($response, $this->view($args['id']));
    }

    /**
     * @param array<string, string> $args
     */
    public function setCycle(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        $cycle = $this->str($this->body($request)['billingCycle'] ?? null);
        if ($cycle === null || !in_array($cycle, Subscription::CYCLES, true)) {
            throw ValidationFailed::fromArray(['billingCycle' => 'Choose monthly or yearly.']);
        }

        $sub = $this->subscriptions->findByTenant($args['id']);
        if ($sub === null || $sub->planId === null) {
            throw new EntityNotFound('Subscription', $args['id']);
        }

        $plan = $this->plans->find($sub->planId);
        if ($plan === null) {
            throw new EntityNotFound('Plan', $sub->planId);
        }

        $this->billing->assignPlan($args['id'], $plan, $cycle);

        return Json::ok($response, $this->view($args['id']));
    }

    /**
     * @param array<string, string> $args
     */
    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        $immediately = filter_var($this->body($request)['immediately'] ?? false, FILTER_VALIDATE_BOOL);

        if ($this->billing->cancel($args['id'], $immediately) === null) {
            throw new EntityNotFound('Subscription', $args['id']);
        }

        return Json::ok($response, $this->view($args['id']));
    }

    /**
     * @param array<string, string> $args
     */
    public function resume(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        if ($this->billing->resume($args['id']) === null) {
            throw new EntityNotFound('Subscription', $args['id']);
        }

        return Json::ok($response, $this->view($args['id']));
    }

    /**
     * @param array<string, string> $args
     */
    public function issueInvoice(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        try {
            $invoice = $this->billing->issueInvoiceNow($args['id']);
        } catch (UniqueConstraintViolationException) {
            // One invoice per subscription per period — this one is already
            // billed for its current period.
            throw ValidationFailed::fromArray(['period' => 'This period has already been invoiced.']);
        }

        if ($invoice === null) {
            throw ValidationFailed::fromArray(['tenant' => 'This organisation has no billable subscription to invoice.']);
        }

        return Json::created($response, $this->view($args['id']), '/v1/operator/tenants/' . $args['id'] . '/billing');
    }

    /**
     * @param array<string, string> $args
     */
    public function payInvoice(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        $invoice = $this->billing->markInvoicePaid($args['id']);
        if ($invoice === null) {
            throw new EntityNotFound('Invoice', $args['id']);
        }

        return Json::ok($response, $invoice->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function voidInvoice(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        $invoice = $this->billing->voidInvoice($args['id']);
        if ($invoice === null) {
            throw new EntityNotFound('Invoice', $args['id']);
        }

        return Json::ok($response, $invoice->toArray());
    }

    // --- helpers --------------------------------------------------------------

    /**
     * The subscription (named with its plan) and the invoices for one tenant.
     *
     * @return array<string, mixed>
     */
    private function view(string $tenantId): array
    {
        $sub = $this->subscriptions->findByTenant($tenantId);
        $plan = $sub?->planId !== null ? $this->plans->find($sub->planId) : null;

        return [
            'subscription' => $sub === null ? null : ($sub->toArray() + ['planName' => $plan?->name]),
            'invoices'     => array_map(static fn (Invoice $i): array => $i->toArray(), $this->invoices->forTenant($tenantId)),
        ];
    }

    private function requireOperator(ServerRequestInterface $request): AuthenticatedUser
    {
        $caller = AuthenticationMiddleware::require($request);
        if (!$caller->isSuperAdmin()) {
            throw new AccessDenied('platform.operator');
        }

        return $caller;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
