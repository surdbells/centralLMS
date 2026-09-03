<?php

declare(strict_types=1);

namespace Central\Api\Application\Saas\Payment;

/** Where to send the customer to pay, from a started checkout. */
final readonly class GatewayInit
{
    public function __construct(
        public string $reference,
        public string $authorizationUrl,
        public ?string $accessCode = null,
    ) {
    }
}
