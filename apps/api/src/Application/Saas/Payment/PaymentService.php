<?php

declare(strict_types=1);

namespace Central\Api\Application\Saas\Payment;

use Central\Api\Application\Saas\BillingService;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Saas\InvoiceRepository;
use Central\Api\Domain\Saas\Payment;
use Central\Api\Domain\Saas\PaymentRepository;
use Central\Api\Support\Clock;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * Collecting an invoice through the gateway.
 *
 * Starting a payment hands back where to send the customer; when they return —
 * or when the gateway's webhook arrives — the charge is confirmed and, if it
 * succeeded, the invoice is marked paid through the billing service (the same
 * seam the operator's manual "mark paid" uses, and idempotent, so the return
 * and the webhook confirming the same payment settle it exactly once).
 */
final class PaymentService
{
    /**
     * @param array<string, mixed> $config the `payments` section
     */
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly BillingService $billing,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly array $config,
    ) {
    }

    /**
     * Starts a checkout for an invoice, returning the payment (with the URL to
     * send the customer to). The invoice must belong to the given tenant and
     * still be owed.
     */
    public function begin(string $invoiceId, string $tenantId, string $email): Payment
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null || $invoice->tenantId !== $tenantId) {
            throw new EntityNotFound('Invoice', $invoiceId);
        }
        if (!$invoice->isOutstanding()) {
            throw ValidationFailed::fromArray(['invoice' => 'This invoice is not awaiting payment.']);
        }

        // Reuse a checkout already in progress for this invoice rather than
        // starting a second one — two live checkouts would let the customer be
        // charged twice for the one invoice.
        $inFlight = $this->payments->findPendingByInvoice((string) $invoice->id);
        if ($inFlight !== null && $inFlight->authorizationUrl !== null) {
            return $inFlight;
        }

        $reference = $this->newReference();

        $init = $this->gateway->initialize(
            reference: $reference,
            amount: $invoice->amountDue,
            currency: $invoice->currency,
            email: $email,
            callbackUrl: $this->callbackUrl(),
            metadata: ['invoiceId' => $invoice->id, 'tenantId' => $tenantId, 'number' => $invoice->number],
        );

        try {
            return $this->payments->create(new Payment(
                id: null,
                tenantId: $tenantId,
                invoiceId: $invoice->id,
                provider: $this->gateway->name(),
                reference: $init->reference,
                gatewayReference: null,
                status: 'pending',
                amount: $invoice->amountDue,
                currency: $invoice->currency,
                authorizationUrl: $init->authorizationUrl,
            ));
        } catch (UniqueConstraintViolationException) {
            // A concurrent "pay" won the race and created the pending payment
            // first — hand back its checkout, and let the transaction we just
            // opened at the gateway lapse unused (it is never shown to anyone).
            $won = $this->payments->findPendingByInvoice((string) $invoice->id);
            if ($won !== null && $won->authorizationUrl !== null) {
                return $won;
            }

            throw new EntityNotFound('Payment', (string) $invoice->id);
        }
    }

    /**
     * Confirms a payment by reference — asking the gateway authoritatively —
     * and settles it. What the customer's return calls. Returns the payment, or
     * null if the reference is unknown.
     */
    public function confirm(string $reference): ?Payment
    {
        $charge = $this->gateway->verify($reference);

        return $this->settle($charge);
    }

    /**
     * Handles a gateway webhook: authenticates the body, and settles the charge
     * it reports. The signed body is authoritative, so it settles even if the
     * customer never returned.
     *
     * @throws PaymentGatewayError when the signature does not check out
     */
    public function handleWebhook(string $rawBody, string $signature): void
    {
        if (!$this->gateway->verifyWebhookSignature($rawBody, $signature)) {
            throw PaymentGatewayError::badResponse($this->gateway->name(), 'webhook signature did not verify');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return;
        }

        $charge = $this->gateway->parseWebhook($payload);
        if ($charge !== null) {
            $this->settle($charge);
        }
    }

    // --- internals ------------------------------------------------------------

    private function settle(GatewayCharge $charge): ?Payment
    {
        $payment = $this->payments->findByReference($charge->reference);
        if ($payment === null) {
            return null;
        }

        // Already settled — the return and the webhook both confirm the same
        // payment, and only the first does the work.
        if ($payment->isSuccessful()) {
            return $payment;
        }

        if (!$charge->successful) {
            return $this->payments->settle($payment->id, 'failed', $charge->gatewayReference, null, $charge->raw);
        }

        // The gateway must have taken at least what we asked for. A charge for
        // less is not this invoice paid.
        if ($charge->amount < $payment->amount) {
            $this->logger->warning('Payment charged less than owed', [
                'reference' => $payment->reference,
                'expected'  => $payment->amount,
                'charged'   => $charge->amount,
            ]);

            return $this->payments->settle($payment->id, 'failed', $charge->gatewayReference, null, $charge->raw);
        }

        $settled = $this->payments->settle($payment->id, 'success', $charge->gatewayReference, $this->clock->now(), $charge->raw);

        // Mark the invoice paid — idempotent, so the second confirmation is a
        // no-op rather than a double credit.
        if ($payment->invoiceId !== null) {
            $this->billing->markInvoicePaid($payment->invoiceId);
        }

        return $settled;
    }

    private function newReference(): string
    {
        return 'CEN-' . bin2hex(random_bytes(12));
    }

    private function callbackUrl(): string
    {
        return (string) ($this->config['callback_url'] ?? '');
    }
}
