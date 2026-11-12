<?php

declare(strict_types=1);

namespace Central\Api\Application\Saas;

/**
 * Whether this deployment bills its organisations.
 *
 * Off on a dedicated single-tenant install, where there is no subscription to
 * show and nobody to pay — the tenant billing endpoints then answer as if they
 * did not exist, and the web app hides the Billing page. Read from
 * `settings['saas']['billing_enabled']` (env BILLING_ENABLED, defaulting to
 * whether Paystack is configured).
 */
final class BillingAvailability
{
    public function __construct(public readonly bool $enabled)
    {
    }

    /**
     * @param array<string, mixed> $settings the application settings array
     */
    public static function fromSettings(array $settings): self
    {
        $saas = $settings['saas'] ?? null;

        return new self(is_array($saas) && ($saas['billing_enabled'] ?? false) === true);
    }
}
