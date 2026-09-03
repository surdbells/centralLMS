import { Component, OnInit, inject, signal } from '@angular/core';
import { DecimalPipe } from '@angular/common';
import { Router } from '@angular/router';
import { OperatorService } from '../core/operator.service';
import type { BillingOverview } from '../core/models';
import { date, money } from '../core/format';
import { invoiceBadgeClass } from '../core/status';

/**
 * Billing across the platform: recurring revenue, what is owed and collected,
 * how subscriptions split by state, and the latest invoices. Every figure comes
 * from `/operator/billing`; an invoice leads to the organisation it belongs to.
 */
@Component({
  selector: 'app-billing',
  standalone: true,
  imports: [DecimalPipe],
  template: `
    <header class="op-topbar">
      <div>
        <h1 class="op-topbar__title">Billing</h1>
        <p class="op-topbar__sub">Revenue, invoices and subscriptions across the platform.</p>
      </div>
    </header>

    <div class="op-content">
      @if (loading()) {
        <div class="op-loading"><span class="op-spinner"></span> Loading billing…</div>
      } @else if (error()) {
        <div class="op-alert op-alert--danger"><i class="bi bi-exclamation-triangle-fill"></i>
          <span>Could not load billing. <button class="op-btn op-btn--sm" (click)="load()">Retry</button></span></div>
      } @else if (data()) {
        @if (data(); as d) {
        <div class="op-tiles" style="margin-bottom:1.25rem">
          <div class="op-tile">
            <div class="op-tile__label"><i class="bi bi-cash-stack"></i> MRR</div>
            <div class="op-tile__value">{{ money(d.mrr) }}</div>
            <div class="op-tile__hint">from active subscriptions</div>
          </div>
          <div class="op-tile">
            <div class="op-tile__label"><i class="bi bi-arrow-repeat"></i> Active subs</div>
            <div class="op-tile__value">{{ (d.subscriptions['active'] || 0) | number }}</div>
            <div class="op-tile__hint">{{ (d.subscriptions['trialing'] || 0) | number }} on trial</div>
          </div>
          <div class="op-tile">
            <div class="op-tile__label"><i class="bi bi-hourglass-split"></i> Outstanding</div>
            <div class="op-tile__value">{{ money(d.invoices.outstanding) }}</div>
            <div class="op-tile__hint">{{ d.invoices.open | number }} open invoice(s)</div>
          </div>
          <div class="op-tile">
            <div class="op-tile__label"><i class="bi bi-check2-circle"></i> Collected</div>
            <div class="op-tile__value">{{ money(d.invoices.collected) }}</div>
            <div class="op-tile__hint">{{ d.invoices.count | number }} invoice(s) in total</div>
          </div>
        </div>

        <div class="op-card">
          <div class="op-card__head"><h2 class="op-card__title">Recent invoices</h2></div>
          @if (d.recentInvoices.length === 0) {
            <div class="op-empty"><i class="bi bi-receipt"></i> No invoices yet.</div>
          } @else {
            <div style="overflow-x:auto">
              <table class="op-table">
                <thead><tr><th>Invoice</th><th>Organisation</th><th>Amount</th><th>Status</th><th>Issued</th></tr></thead>
                <tbody>
                  @for (inv of d.recentInvoices; track inv.id) {
                    <tr class="is-clickable" (click)="openTenant(inv.tenantId)">
                      <td><span class="op-table__code" style="font-family:ui-monospace,Menlo,monospace">{{ inv.number }}</span></td>
                      <td><div class="op-table__name">{{ inv.tenantName }}</div><div class="op-table__code">{{ inv.tenantCode }}</div></td>
                      <td>{{ money(inv.amountDue, inv.currency) }}</td>
                      <td><span [class]="invBadge(inv.status)">{{ inv.status }}</span></td>
                      <td style="color:var(--op-ink-soft)">{{ fmt(inv.issuedAt) }}</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          }
        </div>
        }
      }
    </div>
  `,
})
export class BillingComponent implements OnInit {
  private readonly ops = inject(OperatorService);
  private readonly router = inject(Router);

  readonly data = signal<BillingOverview | null>(null);
  readonly loading = signal(true);
  readonly error = signal(false);

  readonly money = money;

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(false);
    this.ops.billingOverview().subscribe({
      next: (d) => { this.data.set(d); this.loading.set(false); },
      error: () => { this.error.set(true); this.loading.set(false); },
    });
  }

  openTenant(id: string): void {
    void this.router.navigate(['/tenants', id]);
  }

  invBadge(status: BillingOverview['recentInvoices'][number]['status']): string {
    return invoiceBadgeClass(status);
  }

  fmt(iso: string | null): string {
    return date(iso);
  }
}
