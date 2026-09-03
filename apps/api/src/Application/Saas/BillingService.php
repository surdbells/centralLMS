<?php

declare(strict_types=1);

namespace Central\Api\Application\Saas;

use Central\Api\Domain\Saas\Invoice;
use Central\Api\Domain\Saas\InvoiceRepository;
use Central\Api\Domain\Saas\Plan;
use Central\Api\Domain\Saas\PlanRepository;
use Central\Api\Domain\Saas\Subscription;
use Central\Api\Domain\Saas\SubscriptionRepository;
use Central\Api\Support\Clock;
use Doctrine\DBAL\Connection;

/**
 * The billing lifecycle: putting an organisation on a plan, cancelling, raising
 * invoices, and rolling subscriptions forward when their period ends.
 *
 * The price is captured onto the subscription when it is set, so a later plan
 * re-price never rewrites what an organisation is billed. Payment itself is a
 * later phase — an invoice is raised open and settled by {@see markInvoicePaid},
 * which the operator calls now and a gateway will call then.
 */
final class BillingService
{
    /** How long an issued invoice has to be paid. */
    private const NET_DAYS = 14;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly InvoiceRepository $invoices,
        private readonly PlanRepository $plans,
        private readonly Clock $clock,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Starts a fresh subscription on a plan's trial — self-service sign-up and
     * a trial provisioning both land here. A free plan has nothing to trial, so
     * it goes straight to active.
     */
    public function startTrial(string $tenantId, Plan $plan, string $cycle): Subscription
    {
        $now = $this->clock->now();
        $amount = $this->priceFor($plan, $cycle);

        if ($amount <= 0) {
            return $this->subscriptions->save(new Subscription(
                id: null, tenantId: $tenantId, planId: $plan->id, billingCycle: $cycle,
                status: 'active', amount: 0, currency: $plan->currency,
                currentPeriodStart: $now, currentPeriodEnd: $this->advance($now, $cycle),
                trialEndsAt: null, cancelAtPeriodEnd: false, canceledAt: null,
            ));
        }

        $trialEnd = $now->modify('+' . max(0, $plan->trialDays) . ' days');

        return $this->subscriptions->save(new Subscription(
            id: null, tenantId: $tenantId, planId: $plan->id, billingCycle: $cycle,
            status: 'trialing', amount: $amount, currency: $plan->currency,
            currentPeriodStart: $now, currentPeriodEnd: $trialEnd,
            trialEndsAt: $trialEnd, cancelAtPeriodEnd: false, canceledAt: null,
        ));
    }

    /**
     * Puts an organisation on a plan (or moves it to another), keeping where it
     * is in its period. A cancelled subscription is revived onto a fresh period.
     */
    public function assignPlan(string $tenantId, Plan $plan, ?string $cycle = null): Subscription
    {
        $now = $this->clock->now();
        $existing = $this->subscriptions->findByTenant($tenantId);
        $cycle = $cycle ?? $existing?->billingCycle ?? 'monthly';
        $amount = $this->priceFor($plan, $cycle);

        $status = $existing !== null && $existing->isLive() ? $existing->status : 'active';
        $periodStart = $existing !== null && $existing->isLive() ? $existing->currentPeriodStart : $now;
        $periodEnd = $existing !== null && $existing->isLive() ? $existing->currentPeriodEnd : $this->advance($now, $cycle);

        $saved = $this->subscriptions->save(new Subscription(
            id: $existing?->id, tenantId: $tenantId, planId: $plan->id, billingCycle: $cycle,
            status: $status, amount: $amount, currency: $plan->currency,
            currentPeriodStart: $periodStart ?? $now,
            currentPeriodEnd: $periodEnd ?? $this->advance($now, $cycle),
            trialEndsAt: $existing?->trialEndsAt,
            cancelAtPeriodEnd: false, canceledAt: null,
        ));

        // A brand-new active paid subscription is billed for the period it
        // begins — the same advance billing a renewal does, but for the first
        // period, which the renewal job (firing only once a period has elapsed)
        // would otherwise step straight over. A trial defers this to conversion;
        // a plan change on an existing subscription keeps its period and is not
        // re-billed here.
        if ($existing === null
            && $saved->status === 'active'
            && $saved->amount > 0
            && $saved->currentPeriodStart !== null
            && $saved->currentPeriodEnd !== null
        ) {
            $this->raise($saved, $saved->currentPeriodStart, $saved->currentPeriodEnd);
        }

        return $saved;
    }

    /** Ends a subscription — at once, or at the end of the paid-for period. */
    public function cancel(string $tenantId, bool $immediately): ?Subscription
    {
        $sub = $this->subscriptions->findByTenant($tenantId);
        if ($sub === null) {
            return null;
        }

        $now = $this->clock->now();

        return $this->subscriptions->save(new Subscription(
            id: $sub->id, tenantId: $sub->tenantId, planId: $sub->planId, billingCycle: $sub->billingCycle,
            status: $immediately ? 'canceled' : $sub->status,
            amount: $sub->amount, currency: $sub->currency,
            currentPeriodStart: $sub->currentPeriodStart, currentPeriodEnd: $sub->currentPeriodEnd,
            trialEndsAt: $sub->trialEndsAt,
            cancelAtPeriodEnd: !$immediately,
            canceledAt: $immediately ? $now : null,
        ));
    }

    /** Takes back a cancellation that has not yet come due. */
    public function resume(string $tenantId): ?Subscription
    {
        $sub = $this->subscriptions->findByTenant($tenantId);
        if ($sub === null || (!$sub->cancelAtPeriodEnd && $sub->status !== 'canceled')) {
            return $sub;
        }

        return $this->subscriptions->save(new Subscription(
            id: $sub->id, tenantId: $sub->tenantId, planId: $sub->planId, billingCycle: $sub->billingCycle,
            status: $sub->status === 'canceled' ? 'active' : $sub->status,
            amount: $sub->amount, currency: $sub->currency,
            currentPeriodStart: $sub->currentPeriodStart, currentPeriodEnd: $sub->currentPeriodEnd,
            trialEndsAt: $sub->trialEndsAt, cancelAtPeriodEnd: false, canceledAt: null,
        ));
    }

    /** Raises an invoice now for an organisation's current subscription. */
    public function issueInvoiceNow(string $tenantId): ?Invoice
    {
        $sub = $this->subscriptions->findByTenant($tenantId);
        if ($sub === null || $sub->amount <= 0) {
            return null;
        }

        $start = $sub->currentPeriodStart ?? $this->clock->now();
        $end = $sub->currentPeriodEnd ?? $this->advance($start, $sub->billingCycle);

        return $this->raise($sub, $start, $end);
    }

    /** Settles an invoice, and revives a subscription that was behind on it. */
    public function markInvoicePaid(string $invoiceId): ?Invoice
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            return null;
        }

        // Only an outstanding invoice can be paid. Paid, void and written-off
        // are terminal — paying one again would double-count it as collected,
        // and reviving a void as paid would book money that never came in.
        if (!$invoice->isOutstanding()) {
            return $invoice;
        }

        $paid = $this->invoices->updateStatus($invoiceId, 'paid', $this->clock->now(), $invoice->amountDue);

        if ($invoice->subscriptionId !== null) {
            $sub = $this->subscriptions->find($invoice->subscriptionId);
            if ($sub !== null && $sub->status === 'past_due') {
                $this->subscriptions->save($this->withStatus($sub, 'active'));
            }
        }

        return $paid;
    }

    public function voidInvoice(string $invoiceId): ?Invoice
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            return null;
        }

        // A paid invoice is not voided — that would erase a real payment from
        // the books. It is already-void / open / draft / written-off that a
        // void applies to.
        if ($invoice->isPaid()) {
            return $invoice;
        }

        return $this->invoices->updateStatus($invoiceId, 'void', null, 0);
    }

    /**
     * Rolls every subscription whose period has ended forward one cycle, raising
     * the next invoice — the renewal job's work.
     *
     * Each subscription is handled in its own transaction so one bad row does
     * not stop the run, and an invoice is only ever raised once per period.
     *
     * @return array{due:int, renewed:int, invoiced:int, canceled:int, failed:int}
     */
    public function runRenewals(): array
    {
        $now = $this->clock->now();
        $due = $this->subscriptions->due($now);
        $tally = ['due' => count($due), 'renewed' => 0, 'invoiced' => 0, 'canceled' => 0, 'failed' => 0];

        foreach ($due as $sub) {
            try {
                $this->connection->transactional(function () use ($sub, $now, &$tally): void {
                    if ($sub->cancelAtPeriodEnd) {
                        $this->subscriptions->save($this->withStatus($sub, 'canceled', canceledAt: $now));
                        $tally['canceled']++;

                        return;
                    }

                    $start = $sub->currentPeriodEnd ?? $now;
                    $end = $this->advance($start, $sub->billingCycle);

                    // A paid plan is billed for the period now beginning; a free
                    // one simply rolls forward.
                    if ($sub->amount > 0 && !$this->invoices->existsForPeriod((string) $sub->id, $end)) {
                        $this->raise($sub, $start, $end);
                        $tally['invoiced']++;
                    }

                    $this->subscriptions->save(new Subscription(
                        id: $sub->id, tenantId: $sub->tenantId, planId: $sub->planId, billingCycle: $sub->billingCycle,
                        status: 'active', amount: $sub->amount, currency: $sub->currency,
                        currentPeriodStart: $start, currentPeriodEnd: $end,
                        trialEndsAt: $sub->trialEndsAt, cancelAtPeriodEnd: false, canceledAt: null,
                    ));
                    $tally['renewed']++;
                });
            } catch (\Throwable) {
                $tally['failed']++;
            }
        }

        return $tally;
    }

    // --- helpers --------------------------------------------------------------

    private function raise(Subscription $sub, \DateTimeImmutable $start, \DateTimeImmutable $end): Invoice
    {
        $now = $this->clock->now();
        $plan = $sub->planId !== null ? $this->plans->find($sub->planId) : null;
        $name = $plan?->name ?? 'Subscription';

        return $this->invoices->create(new Invoice(
            id: null,
            tenantId: $sub->tenantId,
            subscriptionId: $sub->id,
            number: '',
            status: 'open',
            description: sprintf(
                '%s — %s (%s to %s)',
                $name,
                $sub->billingCycle,
                $start->format('j M Y'),
                $end->format('j M Y'),
            ),
            amountDue: $sub->amount,
            amountPaid: 0,
            currency: $sub->currency,
            periodStart: $start,
            periodEnd: $end,
            issuedAt: $now,
            dueAt: $now->modify('+' . self::NET_DAYS . ' days'),
            paidAt: null,
        ));
    }

    private function priceFor(Plan $plan, string $cycle): int
    {
        return $cycle === 'yearly' ? $plan->priceYearly : $plan->priceMonthly;
    }

    private function advance(\DateTimeImmutable $from, string $cycle): \DateTimeImmutable
    {
        return $from->modify($cycle === 'yearly' ? '+1 year' : '+1 month');
    }

    private function withStatus(Subscription $s, string $status, ?\DateTimeImmutable $canceledAt = null): Subscription
    {
        return new Subscription(
            id: $s->id, tenantId: $s->tenantId, planId: $s->planId, billingCycle: $s->billingCycle,
            status: $status, amount: $s->amount, currency: $s->currency,
            currentPeriodStart: $s->currentPeriodStart, currentPeriodEnd: $s->currentPeriodEnd,
            trialEndsAt: $s->trialEndsAt,
            cancelAtPeriodEnd: $status === 'canceled' ? false : $s->cancelAtPeriodEnd,
            canceledAt: $canceledAt ?? $s->canceledAt,
        );
    }
}
