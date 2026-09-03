<?php

declare(strict_types=1);

namespace Central\Api\Application\Saas\Payment;

/**
 * The gateway could not be reached, refused the request, or answered with
 * something we could not use. Distinct from a payment that simply failed —
 * that is a {@see GatewayCharge} with `successful = false`.
 */
final class PaymentGatewayError extends \RuntimeException
{
    public static function notConfigured(string $provider): self
    {
        return new self(sprintf('The %s payment gateway is not configured.', $provider));
    }

    public static function badResponse(string $provider, string $detail): self
    {
        return new self(sprintf('%s returned an unexpected response: %s', $provider, $detail));
    }
}
