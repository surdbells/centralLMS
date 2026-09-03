<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Saas;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Application\Saas\Payment\PaymentGatewayError;
use Central\Api\Application\Saas\Payment\PaymentService;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ValidationFailed;
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
 * plan they are on, where the current period runs to, and their invoices — and
 * pays an outstanding one through the gateway. No tenant id in the path: the
 * subject is whoever is signed in, so one organisation can never read or pay
 * another's billing.
 */
final class BillingAction
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly InvoiceRepository $invoices,
        private readonly PlanRepository $plans,
        private readonly PaymentService $payments,
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

    /**
     * Starts paying one of the organisation's outstanding invoices, returning
     * where to send the customer to pay.
     *
     * @param array<string, string> $args
     */
    public function pay(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $caller = $this->requireBilling($request);

        try {
            $payment = $this->payments->begin($args['id'], $caller->tenantId ?? '', (string) $caller->email);
        } catch (PaymentGatewayError) {
            // The gateway is misconfigured or unreachable — not the caller's fault.
            throw ValidationFailed::fromArray(['payment' => 'Payments are unavailable right now. Please try again shortly.']);
        }

        return Json::ok($response, [
            'reference'        => $payment->reference,
            'authorizationUrl' => $payment->authorizationUrl,
        ]);
    }

    /**
     * Confirms a payment after the customer returns from the gateway. Safe to
     * call more than once — the webhook may have settled it already.
     */
    public function confirm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = $this->requireBilling($request);

        $reference = $this->str($this->body($request)['reference'] ?? null);
        if ($reference === null) {
            throw ValidationFailed::fromArray(['reference' => 'Give the payment reference to confirm.']);
        }

        try {
            $payment = $this->payments->confirm($reference);
        } catch (PaymentGatewayError) {
            throw ValidationFailed::fromArray(['payment' => 'Could not confirm the payment. Please try again shortly.']);
        }

        // Only the caller's own payment, and only whether it went through.
        $ownsIt = $payment !== null && $payment->tenantId === ($caller->tenantId ?? '');

        return Json::ok($response, [
            'paid'   => $ownsIt && $payment->isSuccessful(),
            'status' => $ownsIt ? $payment->status : 'unknown',
        ]);
    }

    private function requireBilling(ServerRequestInterface $request): AuthenticatedUser
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::BillingManage);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::BillingManage->value);
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
