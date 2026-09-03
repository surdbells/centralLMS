<?php

declare(strict_types=1);

namespace Central\Api\Application\Saas\Payment;

/**
 * The gateway's word on a payment — from a verify, or from a webhook.
 *
 * `reference` is our own reference (what we sent and match back on), not the
 * gateway's; the gateway's is carried separately for the record.
 */
final readonly class GatewayCharge
{
    /**
     * @param int                  $amount minor units, as the gateway reports it
     * @param array<string, mixed> $raw    the gateway's payload, for the trail
     */
    public function __construct(
        public string $reference,
        public bool $successful,
        public int $amount,
        public string $currency,
        public ?string $gatewayReference,
        public array $raw,
    ) {
    }
}
