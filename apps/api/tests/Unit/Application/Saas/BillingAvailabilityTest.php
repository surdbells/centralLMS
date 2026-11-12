<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Saas;

use Central\Api\Application\Saas\BillingAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The deployment-level billing switch: on for the multi-tenant SaaS, off for a
 * dedicated single-tenant install. Anything other than an explicit `true` is
 * off, so a missing or malformed setting never exposes billing.
 */
#[CoversClass(BillingAvailability::class)]
final class BillingAvailabilityTest extends TestCase
{
    #[Test]
    public function enabled_when_the_setting_is_true(): void
    {
        self::assertTrue(BillingAvailability::fromSettings(['saas' => ['billing_enabled' => true]])->enabled);
    }

    #[Test]
    public function disabled_when_the_setting_is_false(): void
    {
        self::assertFalse(BillingAvailability::fromSettings(['saas' => ['billing_enabled' => false]])->enabled);
    }

    #[Test]
    public function disabled_when_the_setting_is_missing(): void
    {
        self::assertFalse(BillingAvailability::fromSettings([])->enabled);
        self::assertFalse(BillingAvailability::fromSettings(['saas' => []])->enabled);
        self::assertFalse(BillingAvailability::fromSettings(['saas' => 'yes'])->enabled);
    }
}
