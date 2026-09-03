import { Component, Input, OnInit, inject, signal } from '@angular/core';
import { OperatorService } from '../core/operator.service';
import type { BillingCycle, Invoice, Subscription, TenantBilling } from '../core/models';
import { date, money } from '../core/format';
import { invoiceBadgeClass, subscriptionBadgeClass, subscriptionLabel } from '../core/status';
import { firstErrorMessage } from '../core/errors';

/**
 * One organisation's billing, for the operator: its subscription and the levers
 * over it — cycle, cancel/resume, raise an invoice — and its invoices, each
 * markable paid or void. Embedded in the organisation detail.
 */
@Component({
  selector: 'app-tenant-billing',
  standalone: true,
  template: `
    <div class="op-billing">
      @if (banner(); as b) {
        <div class="op-alert" [class.op-alert--ok]="b.ok" [class.op-alert--danger]="!b.ok" style="margin-bottom:1rem">
          <i class="bi" [class.bi-check-circle-fill]="b.ok" [class.bi-exclamation-triangle-fill]="!b.ok"></i>
          <span>{{ b.text }}</span>
        </div>
      }

      <div class="op-grid2">
        <!-- Subscription -->
        <div class="op-card">
          <div class="op-card__head">
            <h2 class="op-card__title">Subscription</h2>
            @if (sub()) {
              <button class="op-btn op-btn--ghost op-btn--sm" [disabled]="busy()" (click)="issueInvoice()">
                <i class="bi bi-receipt"></i> Issue invoice
              </button>
            }
          </div>
          <div class="op-card__body">
            @if (loading()) {
              <div class="op-loading"><span class="op-spinner"></span> Loading…</div>
            } @else if (error()) {
              <div class="op-empty"><i class="bi bi-exclamation-triangle" style="color:var(--op-danger)"></i>
                Could not load billing. <div style="margin-top:.5rem"><button class="op-btn op-btn--sm" (click)="load()">Retry</button></div></div>
            } @else {
              @if (sub(); as s) {
              <div style="display:flex;align-items:baseline;justify-content:space-between;gap:.5rem;margin-bottom:.75rem">
                <div>
                  <span [class]="badge(s.status)">{{ label(s.status) }}</span>
                  @if (s.cancelAtPeriodEnd) { <span class="op-badge op-badge--suspended" style="margin-left:.4rem">Ends at period</span> }
                </div>
                <div style="text-align:right">
                  <div style="font-size:1.15rem;font-weight:700">{{ s.amount > 0 ? money(s.amount, s.currency) : 'Free' }}</div>
                  <div style="font-size:.8rem;color:var(--op-ink-faint)">{{ s.planName || '—' }} · {{ s.billingCycle }}</div>
                </div>
              </div>
              <dl class="op-dl">
                <dt>Current period</dt><dd>{{ fmt(s.currentPeriodStart) }} – {{ fmt(s.currentPeriodEnd) }}</dd>
                @if (s.trialEndsAt) { <dt>Trial ends</dt><dd>{{ fmt(s.trialEndsAt) }}</dd> }
                <dt>Started</dt><dd>{{ fmt(s.startedAt) }}</dd>
              </dl>

              <div class="op-field" style="margin:.75rem 0 0">
                <label class="op-field__label">Billing cycle</label>
                <div style="display:flex;gap:.4rem">
                  <button class="op-btn op-btn--sm" [class.op-btn--primary]="s.billingCycle === 'monthly'"
                          [disabled]="busy() || s.billingCycle === 'monthly'" (click)="setCycle('monthly')">Monthly</button>
                  <button class="op-btn op-btn--sm" [class.op-btn--primary]="s.billingCycle === 'yearly'"
                          [disabled]="busy() || s.billingCycle === 'yearly'" (click)="setCycle('yearly')">Yearly</button>
                </div>
              </div>

              <div style="display:flex;gap:.5rem;margin-top:1rem">
                @if (s.cancelAtPeriodEnd || s.status === 'canceled') {
                  <button class="op-btn op-btn--sm" [disabled]="busy()" (click)="resume()"><i class="bi bi-arrow-counterclockwise"></i> Resume</button>
                } @else {
                  <button class="op-btn op-btn--danger op-btn--sm" [disabled]="busy()" (click)="cancel(false)">Cancel at period end</button>
                  <button class="op-btn op-btn--danger op-btn--sm" [disabled]="busy()" (click)="cancel(true)">Cancel now</button>
                }
              </div>
              } @else {
                <div class="op-empty" style="padding:1.5rem"><i class="bi bi-credit-card"></i> No subscription. Put this organisation on a plan to start one.</div>
              }
            }
          </div>
        </div>

        <!-- Invoices -->
        <div class="op-card">
          <div class="op-card__head"><h2 class="op-card__title">Invoices</h2></div>
          <div class="op-card__body" style="padding:0">
            @if (!loading() && invoices().length === 0) {
              <div class="op-empty" style="padding:1.5rem"><i class="bi bi-receipt"></i> No invoices yet.</div>
            } @else if (invoices().length) {
              <div style="overflow-x:auto">
                <table class="op-table">
                  <thead><tr><th>Invoice</th><th>Amount</th><th>Status</th><th>Issued</th><th></th></tr></thead>
                  <tbody>
                    @for (inv of invoices(); track inv.id) {
                      <tr>
                        <td><div class="op-table__code" style="font-family:ui-monospace,Menlo,monospace">{{ inv.number }}</div></td>
                        <td>{{ money(inv.amountDue, inv.currency) }}</td>
                        <td><span [class]="invBadge(inv.status)">{{ inv.status }}</span></td>
                        <td style="color:var(--op-ink-soft)">{{ fmt(inv.issuedAt) }}</td>
                        <td style="text-align:right;white-space:nowrap">
                          @if (inv.status === 'open' || inv.status === 'draft') {
                            <button class="op-btn op-btn--sm op-btn--primary" [disabled]="busy()" (click)="pay(inv)"><i class="bi bi-check2"></i> Paid</button>
                            <button class="op-btn op-btn--sm op-btn--ghost" style="margin-left:.35rem" [disabled]="busy()" (click)="void(inv)">Void</button>
                          }
                        </td>
                      </tr>
                    }
                  </tbody>
                </table>
              </div>
            }
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .op-grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1.25rem; align-items: start; }
    .op-dl { display: grid; grid-template-columns: 42% 58%; gap: .35rem 0; margin: 0; font-size: .88rem; }
    .op-dl dt { color: var(--op-ink-faint); }
    .op-dl dd { margin: 0; font-weight: 500; }
  `],
})
export class TenantBillingComponent implements OnInit {
  private readonly ops = inject(OperatorService);

  @Input({ required: true }) tenantId!: string;

  readonly sub = signal<Subscription | null>(null);
  readonly invoices = signal<readonly Invoice[]>([]);
  readonly loading = signal(true);
  readonly error = signal(false);
  readonly busy = signal(false);
  readonly banner = signal<{ ok: boolean; text: string } | null>(null);

  readonly money = money;

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(false);
    this.ops.tenantBilling(this.tenantId).subscribe({
      next: (b) => this.apply(b),
      error: () => {
        this.error.set(true);
        this.loading.set(false);
      },
    });
  }

  setCycle(cycle: BillingCycle): void {
    this.run(this.ops.setBillingCycle(this.tenantId, cycle), `Billing cycle set to ${cycle}.`);
  }

  cancel(immediately: boolean): void {
    const s = this.sub();
    if (!s) {
      return;
    }
    const msg = immediately ? 'Cancel this subscription now?' : 'Cancel at the end of the current period?';
    if (!confirm(msg)) {
      return;
    }
    this.run(this.ops.cancelSubscription(this.tenantId, immediately), immediately ? 'Subscription cancelled.' : 'Subscription will end at the period end.');
  }

  resume(): void {
    this.run(this.ops.resumeSubscription(this.tenantId), 'Subscription resumed.');
  }

  issueInvoice(): void {
    this.run(this.ops.issueInvoice(this.tenantId), 'Invoice issued.');
  }

  pay(inv: Invoice): void {
    if (this.busy()) {
      return;
    }
    this.busy.set(true);
    this.banner.set(null);
    this.ops.payInvoice(inv.id).subscribe({
      next: () => { this.flash(true, `Invoice ${inv.number} marked paid.`); this.load(); this.busy.set(false); },
      error: (f: unknown) => { this.flash(false, firstErrorMessage(f, 'Could not mark the invoice paid.')); this.busy.set(false); },
    });
  }

  void(inv: Invoice): void {
    if (this.busy() || !confirm(`Void invoice ${inv.number}? It stops counting as owed.`)) {
      return;
    }
    this.busy.set(true);
    this.banner.set(null);
    this.ops.voidInvoice(inv.id).subscribe({
      next: () => { this.flash(true, `Invoice ${inv.number} voided.`); this.load(); this.busy.set(false); },
      error: (f: unknown) => { this.flash(false, firstErrorMessage(f, 'Could not void the invoice.')); this.busy.set(false); },
    });
  }

  badge(status: Subscription['status']): string {
    return subscriptionBadgeClass(status);
  }

  label(status: Subscription['status']): string {
    return subscriptionLabel(status);
  }

  invBadge(status: Invoice['status']): string {
    return invoiceBadgeClass(status);
  }

  fmt(iso: string | null): string {
    return date(iso);
  }

  private run(call: ReturnType<OperatorService['tenantBilling']>, ok: string): void {
    if (this.busy()) {
      return;
    }
    this.busy.set(true);
    this.banner.set(null);
    call.subscribe({
      next: (b) => { this.apply(b); this.flash(true, ok); this.busy.set(false); },
      error: (f: unknown) => { this.flash(false, firstErrorMessage(f, 'That could not be done.')); this.busy.set(false); },
    });
  }

  private apply(b: TenantBilling): void {
    this.sub.set(b.subscription);
    this.invoices.set(b.invoices);
    this.loading.set(false);
  }

  private flash(ok: boolean, text: string): void {
    this.banner.set({ ok, text });
  }
}
