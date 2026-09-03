<?php

declare(strict_types=1);

namespace Central\Api\Application\Saas\Payment;

/**
 * A payment gateway, in the terms the billing layer needs — nothing Paystack-,
 * Flutterwave- or Stripe-specific leaks past here.
 *
 * The flow is the redirect flow: {@see initialize} starts a checkout and hands
 * back where to send the customer; when they return, {@see verify} confirms it,
 * and the gateway's webhook — authenticated by {@see verifyWebhookSignature} and
 * read by {@see parseWebhook} — confirms it authoritatively even if the customer
 * never came back.
 */
interface PaymentGateway
{
    /** The provider this is, e.g. 'paystack'. */
    public function name(): string;

    /**
     * Starts a checkout for one payment.
     *
     * @param  int    $amount   minor units (kobo)
     * @param  array<string, mixed> $metadata carried through and echoed back
     * @throws PaymentGatewayError
     */
    public function initialize(
        string $reference,
        int $amount,
        string $currency,
        string $email,
        string $callbackUrl,
        array $metadata = [],
    ): GatewayInit;

    /**
     * Confirms a payment with the gateway by our reference.
     *
     * @throws PaymentGatewayError
     */
    public function verify(string $reference): GatewayCharge;

    /**
     * Whether a webhook body actually came from the gateway.
     *
     * The signature is over the raw body, so the caller must pass the bytes as
     * received, not a re-encoding of the parsed payload.
     */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool;

    /**
     * The charge a webhook payload describes, or null when the event is not one
     * we act on (only a successful charge settles an invoice).
     *
     * @param array<string, mixed> $payload
     */
    public function parseWebhook(array $payload): ?GatewayCharge;
}
