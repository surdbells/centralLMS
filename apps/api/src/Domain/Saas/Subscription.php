<?php

declare(strict_types=1);

namespace Central\Api\Domain\Saas;

/**
 * An organisation's billing arrangement: the plan, on what terms, and where it
 * is in its lifecycle.
 *
 * The amount is captured here, not read from the plan when a bill is raised, so
 * re-pricing a plan never rewrites what an organisation already agreed to. The
 * period is the window the current bill covers; a trial's period runs to the
 * trial's end.
 */
final readonly class Subscription
{
    public const CYCLES = ['monthly', 'yearly'];
    public const STATUSES = ['trialing', 'active', 'past_due', 'canceled'];

    public function __construct(
        public ?string $id,
        public string $tenantId,
        public ?string $planId,
        public string $billingCycle,
        public string $status,
        public int $amount,
        public string $currency,
        public ?\DateTimeImmutable $currentPeriodStart,
        public ?\DateTimeImmutable $currentPeriodEnd,
        public ?\DateTimeImmutable $trialEndsAt,
        public bool $cancelAtPeriodEnd,
        public ?\DateTimeImmutable $canceledAt,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    public function onTrial(): bool
    {
        return $this->status === 'trialing';
    }

    public function isLive(): bool
    {
        return in_array($this->status, ['trialing', 'active'], true);
    }

    /** Monthly recurring revenue this subscription contributes, in minor units. */
    public function monthlyValue(): int
    {
        if ($this->status !== 'active' || $this->amount <= 0) {
            return 0;
        }

        return $this->billingCycle === 'yearly' ? (int) round($this->amount / 12) : $this->amount;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'tenantId'          => $this->tenantId,
            'planId'            => $this->planId,
            'billingCycle'      => $this->billingCycle,
            'status'            => $this->status,
            'amount'            => $this->amount,
            'currency'          => $this->currency,
            'currentPeriodStart' => $this->currentPeriodStart?->format(\DateTimeInterface::ATOM),
            'currentPeriodEnd'  => $this->currentPeriodEnd?->format(\DateTimeInterface::ATOM),
            'trialEndsAt'       => $this->trialEndsAt?->format(\DateTimeInterface::ATOM),
            'cancelAtPeriodEnd' => $this->cancelAtPeriodEnd,
            'canceledAt'        => $this->canceledAt?->format(\DateTimeInterface::ATOM),
            'startedAt'         => $this->startedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
